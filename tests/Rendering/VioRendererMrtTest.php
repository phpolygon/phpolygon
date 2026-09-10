<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The MRT scene path (docs/rfcs/mrt-gbuffer.md) against the forward path it
 * replaces: same scene, same settings (SSAO on, so the frame needs the
 * G-buffer), rendered once with PHPOLYGON_VIO_MRT=0 and once with =1. The two
 * images must agree — the deferred composite applies the AO exactly where the
 * forward shader did — and the composite's alpha blend must leave pixels
 * nothing was drawn into at the caller's clear colour.
 *
 * Direct3D only (the G-buffer consumers, SSAO / SDF-AO / SSR, are D3D-only, so
 * the MRT path never engages elsewhere); WARP-capable, like the other
 * native-gpu tests.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererMrtTest extends TestCase
{
    private const W = 96;
    private const H = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = @vio_create('auto', ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!in_array(vio_backend_name($ctx), ['d3d11', 'd3d12'], true)) {
            vio_destroy($ctx);
            $this->markTestSkipped('MRT scene path engages on Direct3D only (G-buffer consumers)');
        }
        if (!defined('VIO_FEATURE_MRT') || !vio_supports_feature($ctx, VIO_FEATURE_MRT)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no multiple render targets');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('slab', BoxMesh::generate(8.0, 0.2, 8.0));
        MaterialRegistry::register('orange', new Material(albedo: new Color(0.9, 0.4, 0.15)));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 0.8));
        MaterialRegistry::register('glass', new Material(albedo: new Color(0.3, 0.8, 0.9), alpha: 0.5));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testMrtImageMatchesTheForwardPath(): void
    {
        $scene = self::scene(sky: true, transparent: false);
        [$forward, $mrtRenderer] = $this->renderBothPaths($scene, new Color(0.1, 0.5, 0.9, 1.0));
        [$forwardImg, $forwardActive] = $forward;
        [$mrtImg, $mrtActive] = $mrtRenderer;

        self::assertFalse($forwardActive, 'PHPOLYGON_VIO_MRT=0 keeps the forward path');
        self::assertTrue($mrtActive, 'PHPOLYGON_VIO_MRT=1 with SSAO on takes the MRT path');

        [$mean, $outliers] = self::compare($forwardImg, $mrtImg);
        self::assertLessThan(2.0, $mean, "mean |forward - mrt| per channel (8-bit): $mean");
        self::assertLessThan(0.01, $outliers, "share of pixels differing by more than 12/255: $outliers");

        // The scene is actually lit geometry + sky, not two identical blanks.
        $centre = self::px($mrtImg, intdiv(self::W, 2), intdiv(self::H, 2));
        $corner = self::px($mrtImg, 2, 2);
        self::assertNotSame($centre, $corner, 'box in the middle, sky in the corner');
    }

    public function testCompositeKeepsTheClearColourWhereNothingWasDrawn(): void
    {
        putenv('PHPOLYGON_VIO_MRT=1');
        $renderer = $this->renderer();
        $rgba = $renderer->renderToImage(self::scene(sky: false, transparent: false), self::W, self::H, new Color(0.10, 0.50, 0.90, 1.0));
        self::assertTrue($renderer->mrtActive());

        // No sky: the corner is untouched geometry-free background, which the
        // alpha-blended composite must leave at the caller's clear colour.
        $corner = self::px($rgba, 2, 2);
        self::assertEqualsWithDelta(26, $corner[0], 4, 'corner R (clear)');
        self::assertEqualsWithDelta(128, $corner[1], 4, 'corner G (clear)');
        self::assertEqualsWithDelta(229, $corner[2], 4, 'corner B (clear)');
        // And the box is there.
        self::assertNotSame($corner, self::px($rgba, intdiv(self::W, 2), intdiv(self::H, 2)));
    }

    public function testTransparentGeometryBlendsOnTheMrtPath(): void
    {
        $scene = self::scene(sky: true, transparent: true);
        [[$forwardImg], [$mrtImg, $mrtActive]] = $this->renderBothPaths($scene, new Color(0.1, 0.5, 0.9, 1.0));
        self::assertTrue($mrtActive);

        // The glass box sits in front of the orange box: its pixel is a blend, so
        // it is neither the opaque orange nor the sky. The forward path blends the
        // tonemapped colour, the MRT path blends linear and tonemaps once — the
        // images therefore agree loosely, not to the LSB.
        [$mean] = self::compare($forwardImg, $mrtImg);
        self::assertLessThan(8.0, $mean, "mean |forward - mrt| with a transparent surface: $mean");
        $glass = self::px($mrtImg, intdiv(self::W, 2), intdiv(self::H, 2));
        self::assertGreaterThan(40, $glass[1], 'glass pixel carries the cyan tint (G)');
        self::assertGreaterThan(40, $glass[2], 'glass pixel carries the cyan tint (B)');
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * @return array{0: array{0: string, 1: bool}, 1: array{0: string, 1: bool}} [forward, mrt] as [rgba, mrtActive]
     */
    private function renderBothPaths(RenderCommandList $scene, Color $clear): array
    {
        putenv('PHPOLYGON_VIO_MRT=0');
        $forward = $this->renderer();
        $forwardImg = $forward->renderToImage($scene, self::W, self::H, $clear);
        $forwardActive = $forward->mrtActive();

        putenv('PHPOLYGON_VIO_MRT=1');
        $mrt = $this->renderer();
        $mrtImg = $mrt->renderToImage($scene, self::W, self::H, $clear);
        $mrtActive = $mrt->mrtActive();

        self::assertSame(self::W * self::H * 4, strlen($forwardImg));
        self::assertSame(self::W * self::H * 4, strlen($mrtImg));
        return [[$forwardImg, $forwardActive], [$mrtImg, $mrtActive]];
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        // SSAO Medium needs the G-buffer → the frame qualifies for the MRT path.
        // No shadows keeps the comparison about the split, not shadow sampling.
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: \PHPolygon\Rendering\Quality\ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            bloom: false,
        ));
        return $renderer;
    }

    private static function scene(bool $sky, bool $transparent): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(2.5, 2.0, 3.5), new Vec3(0, 0.3, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.4, 0.45, 0.5), 0.6));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 0.95, 0.9), 1.2));
        if ($sky) {
            $list->add(new SetSky(
                new Vec3(0.3, 0.8, 0.5),
                new Color(1.0, 0.95, 0.9), 1.0,
                new Color(0.1, 0.3, 0.8),
                new Color(0.7, 0.8, 0.9),
                new Color(0.2, 0.15, 0.1),
            ));
        }
        $list->add(new DrawMesh('slab', 'grey', Mat4::translation(0.0, -0.6, 0.0)));
        $list->add(new DrawMesh('box', 'orange', Mat4::identity()));
        if ($transparent) {
            $list->add(new DrawMesh('box', 'glass', Mat4::translation(0.9, 0.2, 1.4)));
        }
        return $list;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $rgba, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;
        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }

    /** @return array{0: float, 1: float} mean absolute channel difference, share of pixels with any channel off by > 12 */
    private static function compare(string $a, string $b): array
    {
        $n = self::W * self::H;
        $sum = 0;
        $outliers = 0;
        for ($i = 0; $i < $n; $i++) {
            $o = $i * 4;
            $worst = 0;
            for ($c = 0; $c < 3; $c++) {
                $d = abs(ord($a[$o + $c]) - ord($b[$o + $c]));
                $sum += $d;
                $worst = max($worst, $d);
            }
            if ($worst > 12) {
                $outliers++;
            }
        }
        return [$sum / ($n * 3), $outliers / $n];
    }
}
