<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;

/**
 * A frame of the engine loop reaches the screen with the 3D scene drawn through the
 * offscreen target (FXAA, the default). On Vulkan php-vio takes a target bound before
 * vio_begin for a frame that is never presented: the renderer used to bind it there,
 * and the whole frame stayed black.
 *
 * PHPOLYGON_TEST_VIO_BACKEND picks the backend (default auto), PHPOLYGON_TEST_VIO_HARDWARE=1
 * the hardware GPU; without it D3D runs on the software adapter.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioEngineFrameOrderTest extends TestCase
{
    private const W = 320;
    private const H = 180;

    /**
     * md5 of this frame rendered by v0.92.0, the engine before the fix, by adapter.
     * The fix must not move a pixel where the frame was right. Vulkan has none: there
     * v0.92.0 presented nothing.
     */
    private const V092 = [
        'd3d12|Microsoft Basic Render Driver' => 'd841aaab16b565e8947043534e809133',
        'd3d12|NVIDIA GeForce RTX 2080' => '411561e2a8a77c28ae484e363443f6e3',
    ];

    /** @return array{0: string, 1: string, 2: string} rgba, backend, adapter */
    private static function frame(): array
    {
        $backend = getenv('PHPOLYGON_TEST_VIO_BACKEND') ?: 'auto';
        $dir = sys_get_temp_dir() . '/phpolygon-frame-order-' . getmypid();
        $engine = new Engine(new EngineConfig(
            width: self::W,
            height: self::H,
            vsync: false,
            offscreen: true,
            is3D: true,
            shaderCachePath: '',
            skipSplash: true,
            vioBackend: $backend,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $dir . '/graphics.json',
            offscreenHardware: getenv('PHPOLYGON_TEST_VIO_HARDWARE') === '1',
        ));
        MeshRegistry::register('frame_order_box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('frame_order_blue', new Material(albedo: new Color(0.1, 0.2, 0.9), roughness: 0.6));
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -4.0), Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $list->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.5), new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMesh('frame_order_box', 'frame_order_blue', Mat4::rotationY(0.6)));

        $frame = 0;
        $rgba = '';
        $adapter = '?';
        $name = '?';
        $engine->onRender(function (Engine $e) use ($list): void {
            $e->renderer3D?->render($list);
            $e->renderer2D->drawRect(20, 20, 60, 40, new Color(1.0, 0.0, 0.0));
        });
        $engine->onUpdate(function (Engine $e) use (&$frame, &$rgba, &$adapter, &$name): void {
            // Outside the frame: php-vio hands back the last presented image.
            if (++$frame === 6) {
                $ctx = $e->window->getContext();
                $rgba = (string) vio_read_pixels($ctx);
                $name = vio_backend_name($ctx);
                $adapter = (string) (vio_backend_info($ctx)['device'] ?? '?');
                $e->stop();
            }
        });
        $engine->run();
        return [$rgba, $name, $adapter];
    }

    /** @return list<int> */
    private static function px(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, ($y * self::W + $x) * 4, 4)));
    }

    #[RunInSeparateProcess]
    public function testTheFrameReachesTheScreen(): void
    {
        [$rgba, $backend, $adapter] = self::frame();
        self::assertSame(self::W * self::H * 4, strlen($rgba), "{$backend} / {$adapter}");

        $rect = self::px($rgba, 50, 40);
        self::assertGreaterThan(200, $rect[0], "2D on top ({$backend} / {$adapter})");
        self::assertLessThan(40, $rect[1]);
        $box = self::px($rgba, self::W >> 1, self::H >> 1);
        self::assertGreaterThan($box[0] + 40, $box[2], "the blue box through the offscreen target ({$backend} / {$adapter})");

        $golden = self::V092[$backend . '|' . $adapter] ?? null;
        if ($golden === null && $backend === 'vulkan') {
            return;
        }
        if ($golden === null) {
            $this->markTestIncomplete(sprintf('no v0.92.0 reference for %s|%s, md5 %s', $backend, $adapter, md5($rgba)));
        }
        self::assertSame($golden, md5($rgba), 'byte for byte the v0.92.0 frame');
    }
}
