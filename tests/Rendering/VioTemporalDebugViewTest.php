<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\PostProcess\VioMotionDebugPass;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * PHPOLYGON_VIO_DEBUG_VIEW replaces the presented frame with one of the
 * temporal inputs: motion (hue = direction, white = still), reactive
 * (transparent coverage), depth (white = near).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioTemporalDebugViewTest extends TestCase
{
    private const W = 96;
    private const H = 64;

    private ?\VioContext $ctx = null;

    /** @return iterable<string, array{0: string}> */
    public static function backends(): iterable
    {
        foreach (['d3d12', 'vulkan', 'opengl'] as $backend) {
            yield $backend => [$backend];
        }
    }

    protected function tearDown(): void
    {
        putenv(VioMotionDebugPass::ENV);
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testSelectedViewParsesTheEnvironment(): void
    {
        putenv(VioMotionDebugPass::ENV);
        $this->assertNull(VioMotionDebugPass::selectedView());
        putenv(VioMotionDebugPass::ENV . '=Motion');
        $this->assertSame('motion', VioMotionDebugPass::selectedView());
        putenv(VioMotionDebugPass::ENV . '=nonsense');
        $this->assertNull(VioMotionDebugPass::selectedView());
    }

    #[DataProvider('backends')]
    public function testMotionViewColoursMovingPixels(string $backend): void
    {
        putenv(VioMotionDebugPass::ENV . '=motion');
        $img = $this->renderPan($backend, 0.4);
        [$r, $g, $b] = self::px($img, intdiv(self::W, 2), intdiv(self::H, 2));
        $this->assertGreaterThan(60, max($r, $g, $b) - min($r, $g, $b), 'a moving pixel is saturated');

        $still = $this->renderPan($backend, 0.0);
        [$r, $g, $b] = self::px($still, intdiv(self::W, 2), intdiv(self::H, 2));
        $this->assertGreaterThan(245, min($r, $g, $b), 'a still pixel is white');
    }

    #[DataProvider('backends')]
    public function testDepthViewIsBrighterNearTheCamera(string $backend): void
    {
        putenv(VioMotionDebugPass::ENV . '=depth');
        $img = $this->renderPan($backend, 0.0);
        $near = self::px($img, intdiv(self::W, 2), self::H - 3); // ground right below the camera
        $far = self::px($img, intdiv(self::W, 2), intdiv(self::H, 2) + 2);
        $this->assertSame($near[0], $near[1], 'grey ramp');
        $this->assertGreaterThan($far[0] + 5, $near[0], 'near is brighter than far');
    }

    #[DataProvider('backends')]
    public function testReactiveViewMarksTransparentSurfacesOnly(string $backend): void
    {
        putenv(VioMotionDebugPass::ENV . '=reactive');
        $img = $this->renderPan($backend, 0.0, withGlass: true);
        $glass = self::px($img, intdiv(self::W, 2), intdiv(self::H, 2));
        $ground = self::px($img, 4, self::H - 4);
        $this->assertGreaterThan(80, $glass[0], 'glass coverage');
        $this->assertLessThan(10, $ground[0], 'opaque ground is not reactive');
    }

    private function renderPan(string $backend, float $pan, bool $withGlass = false): string
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
        $ctx = @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false || vio_backend_name($ctx) !== $backend) {
            $this->markTestSkipped("$backend: no headless context on this machine");
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('ground', PlaneMesh::generate(80.0, 80.0, 4));
        MeshRegistry::register('pane', BoxMesh::generate(2.0, 2.0, 0.05));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.5, 0.5, 0.5)));
        MaterialRegistry::register('glass', new Material(albedo: new Color(0.3, 0.8, 0.9), alpha: 0.5));

        $renderer = new VioRenderer3D($ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            bloom: false,
            antiAliasing: AntiAliasing::Taa,
        ));
        foreach ([0.0, $pan] as $x) {
            $list = new RenderCommandList();
            $list->add(new SetCamera(
                Mat4::lookAt(new Vec3($x, 2.0, 6.0), new Vec3($x, 0.5, 0.0), new Vec3(0, 1, 0)),
                Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 200.0),
            ));
            $list->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 0.6));
            $list->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.4), new Color(1, 1, 1), 1.0));
            $list->add(new DrawMesh('ground', 'ground', Mat4::identity()));
            if ($withGlass) {
                $list->add(new DrawMesh('pane', 'glass', Mat4::translation($x, 1.5, 2.0)));
            }
            vio_begin($ctx);
            $renderer->beginFrame();
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($ctx);
        }
        $rgba = vio_read_pixels($ctx);
        $this->assertSame(self::W * self::H * 4, strlen($rgba));
        return $rgba;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $rgba, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;
        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }
}
