<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Fieldtracing\Bake\SdfVolumeBaker;
use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFieldtracingVolume;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Runtime\PerfProfiler;

/**
 * The SDF trace pass (FieldtracingMode::SdfOcclusion) darkens the floor where it meets
 * a box the baked volume knows, and nowhere far from it; it costs a measurable GPU
 * section `sdfao`. A frame without the pass equals the probes-only frame byte for byte.
 * Direct3D always runs it; elsewhere the test follows whether the pass ran (the `sdfao`
 * mark), so it holds for every backend the renderer gates in. Forward and MRT.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSdfAoTest extends TestCase
{
    private const W = 160;
    private const H = 120;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_TEXTURE_3D') || !vio_supports_feature($ctx, VIO_FEATURE_TEXTURE_3D)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no 3D textures: the SDF tiers degrade to probes only');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(20.0, 0.2, 20.0));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.9, useEnvironmentMap: false));
        MaterialRegistry::register('box', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 0.9));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_PROFILE');
        putenv('PHPOLYGON_VIO_MRT');
        PerfProfiler::reset();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    /** A 1 m box resting on the floor (top at y = -1), the camera looking down at it. */
    private static function scene(): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.2, 4.0), new Vec3(0.0, -2.4, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.12));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 0.1));
        $list->add(new DrawMesh('floor', 'floor', Mat4::translation(0.0, -1.1, 0.0)));
        $list->add(new DrawMesh('box', 'box', Mat4::translation(0.0, -0.5, 0.0)));
        // The volume knows the box only (BoxSdf takes the size first, then the centre).
        $vol = SdfVolumeBaker::bake(new BoxSdf(new Vec3(1.0, 1.0, 1.0), new Vec3(0.0, -0.5, 0.0)), new Vec3(-3.0, -1.2, -3.0), new Vec3(3.0, 1.5, 3.0), 32);
        $max = $vol->max();
        $list->add(new SetFieldtracingVolume(
            $vol->toRgba8(4.0), $vol->nx, $vol->ny, $vol->nz, $vol->origin,
            new Vec3($max->x - $vol->origin->x, $max->y - $vol->origin->y, $max->z - $vol->origin->z), 4.0, 1,
        ));
        return $list;
    }

    /** @return array{0: string, 1: array<string, float>} pixels and GPU sections of the last frame */
    private function frame(FieldtracingMode $mode): array
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: true,
            ambientOcclusion: ScreenSpaceAO::Off,
            fieldtracing: $mode,
        ));
        $list = self::scene();
        // GPU sections follow the last completed frame.
        for ($i = 0; $i < 4; $i++) {
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($this->ctx);
        }
        $rgba = (string) vio_read_pixels($this->ctx);
        self::assertSame(self::W * self::H * 4, strlen($rgba));
        return [$rgba, $renderer->gpuPassTimesMs()];
    }

    private static function luma(string $rgba, int $x, int $y): float
    {
        $o = ($y * self::W + $x) * 4;
        return 0.2126 * ord($rgba[$o]) + 0.7152 * ord($rgba[$o + 1]) + 0.0722 * ord($rgba[$o + 2]);
    }

    #[DataProvider('paths')]
    public function testSdfOcclusionDarkensTheFootOfTheBox(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        self::assertNotNull($this->ctx);
        $direct3d = in_array(vio_backend_name($this->ctx), ['d3d11', 'd3d12'], true);
        $marks = function_exists('vio_gpu_timings') && defined('VIO_FEATURE_GPU_TIMESTAMP')
            && vio_supports_feature($this->ctx, VIO_FEATURE_GPU_TIMESTAMP);

        [$off] = $this->frame(FieldtracingMode::ProbesOnly);
        [$on, $gpu] = $this->frame(FieldtracingMode::SdfOcclusion);

        // Which pixels darkened: the floor along the box's base, nothing far away.
        $near = 0;
        $far = 0;
        $deepest = 0.0;
        $sumY = 0;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $d = self::luma($off, $x, $y) - self::luma($on, $x, $y);
                if ($d <= 4.0) {
                    continue;
                }
                $deepest = max($deepest, $d);
                // The box covers columns ~60..100 in the upper half; its foot is there too.
                if ($x >= 45 && $x <= 115 && $y < 60) {
                    $near++;
                    $sumY += $y;
                } else {
                    $far++;
                }
            }
        }

        $ran = $marks ? isset($gpu['sdfao']) : $direct3d;
        if ($direct3d) {
            self::assertTrue($ran, 'Direct3D runs the SDF trace pass (no sdfao section)');
        }
        if (!$ran) {
            self::assertSame(md5($off), md5($on), 'without the pass the frame is the probes-only frame');
            return;
        }

        $label = sprintf('near %d px, far %d px, deepest %.0f', $near, $far, $deepest);
        self::assertGreaterThan(150, $near, "{$label}: the floor darkens around the foot of the box");
        self::assertGreaterThan(30.0, $deepest, "{$label}: a visible contact shadow, not rounding");
        self::assertLessThan(max(10, intdiv($near, 10)), $far, "{$label}: nothing darkens away from the box");
        // The foot is the lower edge of the box: below the box's centre row in screen space.
        self::assertGreaterThan(20.0, $sumY / $near, "{$label}: at the foot, not mirrored to the top of the frame");
        if ($marks) {
            self::assertGreaterThan(0.0, $gpu['sdfao'], 'the pass has a GPU cost');
        }
    }
}
