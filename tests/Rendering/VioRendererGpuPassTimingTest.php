<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawOutline;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Runtime\PerfProfiler;

/**
 * GPU time per pass: with an active profiler every pass that ran shows up as
 * `render3d.gpu.<pass>`, the sections fit into the frame, and a running game
 * sets no mark at all (a mark ends the command buffer on Metal).
 *
 * Separate processes: a D3D11 context opened after VioGrassRenderTest's in the
 * same process loses its device on the first frame, marks or not.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[RunTestsInSeparateProcesses]
final class VioRendererGpuPassTimingTest extends TestCase
{
    private const int W = 96;
    private const int H = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();
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
        putenv('PHPOLYGON_PROFILE');
        putenv('PHPOLYGON_VIO_MRT');
        PerfProfiler::reset();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return [
            'd3d12' => ['d3d12'],
            'd3d11' => ['d3d11'],
            'vulkan' => ['vulkan'],
            'opengl' => ['opengl'],
            'metal' => ['metal'],
        ];
    }

    #[DataProvider('backends')]
    public function testEveryPassThatRanIsMeasured(string $backend): void
    {
        $ctx = $this->context($backend);
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Low,
            ambientOcclusion: ScreenSpaceAO::Off,
            bloom: true,
        ));
        $this->frames($renderer, self::scene(sky: true, outline: true));

        $expected = ['frame_start', 'shadow', 'envcube', 'sky', 'opaque', 'transparent', 'outline', 'bloom', 'post'];
        if (!defined('VIO_FEATURE_STENCIL') || !vio_supports_feature($ctx, VIO_FEATURE_STENCIL)) {
            $expected = array_values(array_diff($expected, ['outline']));
        }
        // The environment cube renders on the first frame only and may be gone
        // from the last completed one.
        $passes = $renderer->gpuPassTimesMs();
        self::assertSame(array_values(array_diff($expected, ['envcube'])), array_keys($passes), $backend);

        $snapshot = PerfProfiler::snapshot();
        foreach ($expected as $pass) {
            if ($pass === 'envcube' && !isset($snapshot['render3d.gpu.envcube'])) {
                continue; // no cube render targets on this backend
            }
            self::assertArrayHasKey('render3d.gpu.' . $pass, $snapshot, $backend);
            self::assertGreaterThan(0, $snapshot['render3d.gpu.' . $pass]['calls']);
        }

        foreach ($passes as $pass => $ms) {
            self::assertGreaterThanOrEqual(0.0, $ms, $pass);
        }
        $frame = $renderer->gpuFrameTimeMs();
        self::assertGreaterThan(0.0, $frame);
        self::assertLessThanOrEqual($frame + 1e-3, array_sum($passes), 'the sections fit into the frame');
    }

    public function testForwardPathMeasuresGbufferAndAo(): void
    {
        putenv('PHPOLYGON_VIO_MRT=0');
        $this->direct3dContext();
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $this->frames($renderer, self::scene(sky: false, outline: false));

        self::assertFalse($renderer->mrtActive());
        self::assertSame(['frame_start', 'gbuffer', 'ssao', 'opaque', 'transparent'], array_keys($renderer->gpuPassTimesMs()));
    }

    public function testMrtPathMeasuresAoAndComposite(): void
    {
        putenv('PHPOLYGON_VIO_MRT=1');
        $this->direct3dContext();
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $this->frames($renderer, self::scene(sky: false, outline: false));

        self::assertTrue($renderer->mrtActive());
        self::assertSame(['frame_start', 'opaque', 'transparent', 'ssao', 'composite'], array_keys($renderer->gpuPassTimesMs()));
    }

    public function testReflectionsAndTemporalResolveAreMeasured(): void
    {
        $this->direct3dContext();
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Taa,
            ssr: ScreenSpaceReflections::Low,
            hdr: true,
            bloom: false,
        ));
        $this->frames($renderer, self::scene(sky: false, outline: false));

        $passes = array_keys($renderer->gpuPassTimesMs());
        self::assertContains('ssr', $passes);
        self::assertContains('taa', $passes);
        self::assertContains('post', $passes);
        self::assertLessThan(array_search('taa', $passes, true), array_search('ssr', $passes, true));
    }

    public function testPassesThatDidNotRunLeaveNoEntry(): void
    {
        $this->context('auto');
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $this->frames($renderer, self::scene(sky: false, outline: false));

        self::assertSame(['frame_start', 'opaque', 'transparent'], array_keys($renderer->gpuPassTimesMs()));
    }

    public function testNoMarkWithoutProfiler(): void
    {
        putenv('PHPOLYGON_PROFILE');
        PerfProfiler::reset();
        $ctx = $this->context('auto');
        $renderer = $this->renderer(new GraphicsSettings(shadowQuality: ShadowQuality::Low, bloom: true));
        $this->frames($renderer, self::scene(sky: true, outline: false));

        self::assertSame([], vio_gpu_timings($ctx), 'the frames carried no mark');
        self::assertSame([], $renderer->gpuPassTimesMs());
        self::assertSame([], PerfProfiler::snapshot());
    }

    public function testBackendWithoutTimestampsReportsNothing(): void
    {
        $ctx = @vio_create('null', ['width' => self::W, 'height' => self::H, 'headless' => true]);
        if ($ctx === false) {
            $this->markTestSkipped('php-vio without the null backend');
        }
        $this->ctx = $ctx;
        self::assertFalse(vio_supports_feature($ctx, VIO_FEATURE_GPU_TIMESTAMP));

        $renderer = $this->renderer(new GraphicsSettings(shadowQuality: ShadowQuality::Low, bloom: true));
        $this->frames($renderer, self::scene(sky: true, outline: false));

        self::assertSame([], $renderer->gpuPassTimesMs());
        self::assertArrayNotHasKey('render3d.gpu.opaque', PerfProfiler::snapshot());
    }

    public function testMarksStopAtTheLimit(): void
    {
        $ctx = $this->context('auto');
        $renderer = $this->renderer(new GraphicsSettings(
            shadowQuality: ShadowQuality::Low,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = self::scene(sky: true, outline: false);
        vio_begin($ctx);
        $renderer->beginFrame();
        for ($i = 0; $i < 10; $i++) {
            $renderer->render($list);
        }
        $marks = (new \ReflectionProperty(VioRenderer3D::class, 'gpuMarkCount'))->getValue($renderer);
        $renderer->endFrame();
        vio_end($ctx);

        self::assertSame(32, $marks);
    }

    private function context(string $backend): \VioContext
    {
        // Each test builds a renderer: compile the shader set only once.
        $cache = sys_get_temp_dir() . '/phpolygon-gpu-pass-timing';
        if (!is_dir($cache)) {
            mkdir($cache);
        }
        $ctx = @vio_create($backend, [
            'width' => self::W,
            'height' => self::H,
            'headless' => true,
            'vsync' => false,
            'shader_cache' => $cache,
        ]);
        if ($ctx === false) {
            $this->markTestSkipped("vio_create($backend, headless) unavailable");
        }
        if ($backend !== 'auto' && vio_backend_name($ctx) !== $backend) {
            vio_destroy($ctx);
            $this->markTestSkipped("$backend unavailable");
        }
        $this->ctx = $ctx;
        if (!function_exists('vio_gpu_timestamp') || !defined('VIO_FEATURE_GPU_TIMESTAMP')
            || !vio_supports_feature($ctx, VIO_FEATURE_GPU_TIMESTAMP)
        ) {
            $this->markTestSkipped('no named GPU timestamps on this backend');
        }
        return $ctx;
    }

    /** The G-buffer consumers (SSAO, SSR) run on Direct3D only. */
    private function direct3dContext(): void
    {
        $ctx = $this->context('auto');
        if (!in_array(vio_backend_name($ctx), ['d3d11', 'd3d12'], true)) {
            $this->markTestSkipped('G-buffer passes engage on Direct3D only');
        }
    }

    private function renderer(GraphicsSettings $settings): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings($settings);
        return $renderer;
    }

    /** Enough frames for the first ones to complete and report. */
    private function frames(VioRenderer3D $renderer, RenderCommandList $list): void
    {
        self::assertNotNull($this->ctx);
        for ($i = 0; $i < 8; $i++) {
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($this->ctx);
        }
    }

    private static function scene(bool $sky, bool $outline): RenderCommandList
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
        $list->add(new DrawMesh('box', 'glass', Mat4::translation(0.9, 0.2, 1.4)));
        if ($outline) {
            $list->add(new DrawOutline('box', Mat4::identity(), new Color(0.0, 1.0, 1.0), 2.0));
        }
        return $list;
    }
}
