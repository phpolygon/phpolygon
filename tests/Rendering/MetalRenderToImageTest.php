<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\MetalRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Testing\VisualTestCase;

/**
 * Native-Metal headless pixel VRT (macOS only). Renders a lit box off-screen
 * via MetalRenderer3D::renderToImage() (no window/drawable) and reads the
 * framebuffer back through php-metalgpu.
 *
 * Skipped everywhere the `metal` extension is absent (Linux/Windows CI, headless
 * boxes without a Metal GPU), so it only runs on macOS runners. Asserts scene
 * structure (background = clear colour, centre = shaded geometry) rather than a
 * committed pixel baseline, because Metal output varies across GPU models.
 */
#[RequiresPhpExtension('metal')]
#[RequiresOperatingSystem('Darwin')]
class MetalRenderToImageTest extends TestCase
{
    use VisualTestCase;

    private const W = 128;
    private const H = 128;

    protected function setUp(): void
    {
        if (!extension_loaded('metal')) {
            $this->markTestSkipped('metal extension not loaded');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    /** Render the shared "lit box on blue clear" scene off-screen → RGBA. */
    private function renderBoxScene(): string
    {
        $renderer = new MetalRenderer3D(self::W, self::H, 0); // handle 0 = headless

        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('mat', new Material(albedo: new Color(0.9, 0.4, 0.15)));

        $view = Mat4::lookAt(new Vec3(2.5, 2.5, 3.5), new Vec3(0, 0, 0), new Vec3(0, 1, 0));
        $proj = Mat4::perspective(deg2rad(55.0), self::W / self::H, 0.1, 100.0);

        $list = new RenderCommandList();
        $list->add(new SetCamera($view, $proj));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 1, 1), 1.2));
        $list->add(new DrawMesh('box', 'mat', Mat4::identity()));

        return $renderer->renderToImage($list, self::W, self::H, new Color(0.10, 0.50, 0.90, 1.0));
    }

    public function testRendersLitBoxOffscreen(): void
    {
        $w = self::W;
        $h = self::H;
        $rgba = $this->renderBoxScene();

        self::assertSame($w * $h * 4, strlen($rgba), 'read-back size must be width*height*4');

        // Corner is background: the exact clear colour (0.10/0.50/0.90 → 26/128/229).
        [$cr, $cg, $cb] = $this->pixel($rgba, $w, 2, 2);
        self::assertEqualsWithDelta(26, $cr, 3, 'corner R (clear)');
        self::assertEqualsWithDelta(128, $cg, 3, 'corner G (clear)');
        self::assertEqualsWithDelta(229, $cb, 3, 'corner B (clear)');

        // Centre is the shaded box — must differ from the clear colour.
        [$mr, $mg, $mb] = $this->pixel($rgba, $w, $w >> 1, $h >> 1);
        self::assertGreaterThan(
            20,
            abs($mr - 26) + abs($mg - 128) + abs($mb - 229),
            'centre pixel must be the rendered box, not the background clear colour',
        );

        // A real 3D render has many shades (lighting across faces), not a flat fill.
        self::assertGreaterThan(3, $this->distinctColors($rgba), 'expected shaded geometry');
    }

    /**
     * Pixel snapshot of the same scene. A generous tolerance absorbs minor
     * GPU-model differences (AA / rounding) between Macs — the reference is
     * per-backend (`-metal-...`). Regenerate with PHPOLYGON_UPDATE_SNAPSHOTS=1.
     */
    public function testBoxSnapshot(): void
    {
        $rgba = $this->renderBoxScene();
        $this->assertRgbaScreenshot(
            $rgba,
            self::W,
            self::H,
            'metal-box',
            'metal',
            threshold: 0.15,
            maxDiffPixelRatio: 0.03,
        );
    }

    /** @return array{0:int,1:int,2:int} */
    private function pixel(string $rgba, int $width, int $x, int $y): array
    {
        $i = ($y * $width + $x) * 4;
        return [ord($rgba[$i]), ord($rgba[$i + 1]), ord($rgba[$i + 2])];
    }

    private function distinctColors(string $rgba): int
    {
        $seen = [];
        $n = strlen($rgba);
        for ($i = 0; $i + 3 < $n; $i += 4) {
            $seen[$rgba[$i] . $rgba[$i + 1] . $rgba[$i + 2]] = true;
        }
        return count($seen);
    }
}
