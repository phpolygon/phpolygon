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
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Runtime\PerfProfiler;

/**
 * The renderer surfaces php-vio's GPU frame timestamps: after a few frames
 * {@see VioRenderer3D::gpuFrameTimeMs()} holds the GPU time of a completed
 * frame and the profiler carries it as the `render3d.gpu` section, so the
 * PerfOverlay can show CPU and GPU cost next to each other.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererGpuFrameTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_PROFILE');
        PerfProfiler::reset();
    }

    public function testGpuFrameTimeIsReportedAndRecordedAsProfilerSection(): void
    {
        if (!function_exists('vio_gpu_frame_time')) {
            $this->markTestSkipped('php-vio without vio_gpu_frame_time (< 2.12)');
        }
        $w = 64;
        $h = 64;
        $ctx = @vio_create('auto', ['width' => $w, 'height' => $h, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_GPU_TIMESTAMP') || !vio_supports_feature($ctx, VIO_FEATURE_GPU_TIMESTAMP)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no GPU timestamps');
        }

        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('mat', new Material(albedo: new Color(0.9, 0.4, 0.15)));

        $renderer = new VioRenderer3D($ctx, $w, $h);
        self::assertSame(-1.0, $renderer->gpuFrameTimeMs(), 'no frame completed yet');

        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(2.5, 2.5, 3.5), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), $w / $h, 0.1, 100.0),
        ));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 1, 1), 1.2));
        $list->add(new DrawMesh('box', 'mat', Mat4::identity()));

        // renderToImage() reads the target back, which waits for the GPU, so a
        // handful of frames is enough for the timestamp ring to deliver.
        for ($i = 0; $i < 5; $i++) {
            $renderer->renderToImage($list, $w, $h, new Color(0.1, 0.5, 0.9, 1.0));
        }

        $ms = $renderer->gpuFrameTimeMs();
        self::assertGreaterThanOrEqual(0.0, $ms, 'a completed frame reports its GPU time');
        self::assertLessThan(5000.0, $ms, 'plausible GPU time');

        $snapshot = PerfProfiler::snapshot();
        self::assertArrayHasKey('render3d.gpu', $snapshot, 'GPU time is a profiler section');
        self::assertGreaterThan(0, $snapshot['render3d.gpu']['calls']);

        vio_destroy($ctx);
    }
}
