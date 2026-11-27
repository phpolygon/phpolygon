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
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Lighting\LightGridGate;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The Full program without the light grid: a frame whose point lights fit the uniform
 * array draws with the grid-less twin (the grid's storage buffers cost the early depth
 * test on Direct3D), a frame with more lights takes the grid. The image must not change.
 *
 * Separate processes: a D3D11 context opened after another test's loses its device.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[Group('gpu')]
#[RunTestsInSeparateProcesses]
final class VioLightGridGateTest extends TestCase
{
    private const int W = 128;
    private const int H = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        MeshRegistry::register('floor', BoxMesh::generate(24.0, 0.2, 24.0));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.8));
        MaterialRegistry::register('leaf', new Material(albedo: new Color(0.2, 0.7, 0.2), roughness: 0.8, alphaCutoff: 0.5));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_LIGHT_GRID_GATE');
        putenv('PHPOLYGON_VIO_MRT');
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return ['d3d12' => ['d3d12'], 'd3d11' => ['d3d11'], 'vulkan' => ['vulkan'], 'opengl' => ['opengl']];
    }

    #[DataProvider('backends')]
    public function testFewLightsDrawWithoutTheGridAndTheImageStaysTheSame(string $backend): void
    {
        $this->context($backend);
        $list = $this->scene(5);

        putenv('PHPOLYGON_LIGHT_GRID_GATE=0');
        $old = $this->newRenderer();
        $oldImage = $old->renderToImage($list, self::W, self::H, new Color(0.1, 0.2, 0.4, 1.0));
        self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, $old->frameBaseProgramVariant(), 'the switch off: the old Full program');

        putenv('PHPOLYGON_LIGHT_GRID_GATE');
        $gated = $this->newRenderer();
        $image = $gated->renderToImage($list, self::W, self::H, new Color(0.1, 0.2, 0.4, 1.0));
        self::assertSame(ProcModeShaderRegistry::NOGRID_VARIANT, $gated->frameBaseProgramVariant(), $backend . ': five lights fit the uniforms');
        self::assertSame(md5($oldImage), md5($image), $backend . ': the image of the old Full path, every pixel');
    }

    #[DataProvider('backends')]
    public function testMoreThanEightLightsTakeTheGridAtOnce(string $backend): void
    {
        $this->context($backend);
        $renderer = $this->newRenderer();
        $renderer->renderToImage($this->scene(3), self::W, self::H, new Color(0.1, 0.2, 0.4, 1.0));
        self::assertSame(ProcModeShaderRegistry::NOGRID_VARIANT, $renderer->frameBaseProgramVariant());
        $renderer->renderToImage($this->scene(12), self::W, self::H, new Color(0.1, 0.2, 0.4, 1.0));
        self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, $renderer->frameBaseProgramVariant(), $backend . ': twelve lights need the grid');
    }

    public function testTheGridIsHeldAndThenLeft(): void
    {
        $this->context('auto');
        $renderer = $this->newRenderer();
        $clear = new Color(0.1, 0.2, 0.4, 1.0);
        $renderer->renderToImage($this->scene(12), self::W, self::H, $clear);
        $few = $this->scene(2);
        for ($i = 1; $i < LightGridGate::HOLD_FRAMES; $i++) {
            $renderer->renderToImage($few, self::W, self::H, $clear);
            self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, $renderer->frameBaseProgramVariant(), 'quiet frame ' . $i);
        }
        $renderer->renderToImage($few, self::W, self::H, $clear);
        self::assertSame(ProcModeShaderRegistry::NOGRID_VARIANT, $renderer->frameBaseProgramVariant());
    }

    /** Twelve lights: the grid in both paths, so the images are the same by construction. */
    public function testTheGridImageIsUntouched(): void
    {
        $this->context('auto');
        $clear = new Color(0.1, 0.2, 0.4, 1.0);
        putenv('PHPOLYGON_LIGHT_GRID_GATE=0');
        $a = $this->newRenderer()->renderToImage($this->scene(12), self::W, self::H, $clear);
        putenv('PHPOLYGON_LIGHT_GRID_GATE');
        $b = $this->newRenderer()->renderToImage($this->scene(12), self::W, self::H, $clear);
        self::assertSame(md5($a), md5($b));
    }

    /** While a surface cut runs the cut program is the base: its grid-less twin is used. */
    public function testASurfaceCutKeepsTheGridlessProgram(): void
    {
        $this->context('auto');
        ProcModeShaderRegistry::enableInstanceParams();
        ProcModeShaderRegistry::registerSurfaceGlsl('', "#ifdef PHPOLYGON_SURFACE_CUT\n    if (v_instance_params.x > 0.5) {\n        discard;\n    }\n#endif\n", '', '');
        ProcModeShaderRegistry::setSurfaceCut(true);
        $clear = new Color(0.1, 0.2, 0.4, 1.0);

        putenv('PHPOLYGON_LIGHT_GRID_GATE=0');
        $old = $this->newRenderer();
        $oldImage = $old->renderToImage($this->scene(4), self::W, self::H, $clear);
        self::assertSame(ProcModeShaderRegistry::SURFACE_CUT_VARIANT, $old->frameBaseProgramVariant());

        putenv('PHPOLYGON_LIGHT_GRID_GATE');
        $gated = $this->newRenderer();
        $image = $gated->renderToImage($this->scene(4), self::W, self::H, $clear);
        self::assertSame(ProcModeShaderRegistry::NOGRID_CUT_VARIANT, $gated->frameBaseProgramVariant());
        self::assertSame(md5($oldImage), md5($image));
    }

    /** A game's own program variant (an early-return look) draws through its grid-less twin: same image. */
    public function testAGamesVariantHasAGridlessTwinWithTheSameImage(): void
    {
        $this->context('auto');
        MaterialRegistry::register('flat_mode', new Material(albedo: new Color(0.2, 0.6, 0.9)));
        ProcModeRegistry::map('flat_mode', 5);
        ProcModeShaderRegistry::registerGlsl(
            5,
            '',
            "    } else if (u_proc_mode == 5) {\n        frag_color = outputColor(texAlbedo * 0.5, alpha);\n        return;\n    } else",
        );
        ProcModeShaderRegistry::defineVariant('flat', [5], \PHPolygon\Rendering\ShaderPath::cases());
        $list = $this->scene(4);
        $list->add(new DrawMesh('box', 'flat_mode', Mat4::translation(-3.0, 0.0, 0.0)));
        $clear = new Color(0.1, 0.2, 0.4, 1.0);

        putenv('PHPOLYGON_LIGHT_GRID_GATE=0');
        $old = $this->newRenderer()->renderToImage($list, self::W, self::H, $clear);
        putenv('PHPOLYGON_LIGHT_GRID_GATE');
        $gated = $this->newRenderer();
        $image = $gated->renderToImage($list, self::W, self::H, $clear);
        self::assertSame(ProcModeShaderRegistry::NOGRID_VARIANT, $gated->frameBaseProgramVariant());
        self::assertSame(md5($old), md5($image));
        self::assertTrue($gated->gridlessVariantCompiled('flat'), 'the variant drew through its +nogrid twin');
    }

    /** The Lite program has no grid itself, but a game's variant in a Lite frame does: it takes its twin too. */
    public function testLiteFramesGiveAGamesVariantItsGridlessTwin(): void
    {
        $this->context('auto');
        MaterialRegistry::register('flat_mode', new Material(albedo: new Color(0.2, 0.6, 0.9)));
        ProcModeRegistry::map('flat_mode', 5);
        ProcModeShaderRegistry::registerGlsl(
            5,
            '',
            "    } else if (u_proc_mode == 5) {\n        frag_color = outputColor(texAlbedo * 0.5, alpha);\n        return;\n    } else",
        );
        ProcModeShaderRegistry::defineVariant('flat', [5], \PHPolygon\Rendering\ShaderPath::cases());
        $list = $this->scene(4);
        $list->add(new DrawMesh('box', 'flat_mode', Mat4::translation(-3.0, 0.0, 0.0)));
        $clear = new Color(0.1, 0.2, 0.4, 1.0);
        $lite = new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, shaderQuality: ShaderQuality::Lite);

        putenv('PHPOLYGON_LIGHT_GRID_GATE=0');
        $old = $this->newRenderer($lite);
        $oldImage = $old->renderToImage($list, self::W, self::H, $clear);
        self::assertFalse($old->gridlessVariantCompiled('flat'));
        putenv('PHPOLYGON_LIGHT_GRID_GATE');
        $gated = $this->newRenderer($lite);
        $image = $gated->renderToImage($list, self::W, self::H, $clear);
        self::assertSame(ProcModeShaderRegistry::LITE_VARIANT, $gated->frameBaseProgramVariant());
        self::assertTrue($gated->gridlessVariantCompiled('flat'), 'the variant has its +nogrid twin in a Lite frame');
        self::assertSame(md5($oldImage), md5($image));
    }

    /**
     * Temporal anti-aliasing switched on after a first frame: the motion twin of the grid-less program
     * did not exist yet and the pipeline built on it crashed the process (found by VioReactiveMaterialTest).
     */
    public function testTemporalAntiAliasingAfterAGridlessFrame(): void
    {
        $this->context('auto');
        $clear = new Color(0.1, 0.2, 0.4, 1.0);
        $renderer = $this->newRenderer();
        $renderer->renderToImage($this->scene(3), self::W, self::H, $clear);
        self::assertSame(ProcModeShaderRegistry::NOGRID_VARIANT, $renderer->frameBaseProgramVariant());
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Taa, bloom: false, hdr: false));
        for ($i = 0; $i < 3; $i++) {
            $image = $renderer->renderToImage($this->scene(3), self::W, self::H, $clear);
        }
        self::assertSame(self::W * self::H * 4, strlen($image));
        self::assertSame(ProcModeShaderRegistry::NOGRID_VARIANT, $renderer->frameBaseProgramVariant());
    }

    /** The game's usual frame: sun shadows and SSAO on the G-buffer (MRT) path, shadows on the forward path. */
    #[DataProvider('mrtPaths')]
    public function testTheImageStaysTheSameWithShadowsAndAmbientOcclusion(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $this->context('auto');
        $list = $this->scene(5);
        $clear = new Color(0.1, 0.2, 0.4, 1.0);
        $settings = new GraphicsSettings(
            shadowQuality: ShadowQuality::High,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        );

        putenv('PHPOLYGON_LIGHT_GRID_GATE=0');
        $old = $this->newRenderer($settings);
        $oldImage = $old->renderToImage($list, self::W, self::H, $clear);
        putenv('PHPOLYGON_LIGHT_GRID_GATE');
        $gated = $this->newRenderer($settings);
        $image = $gated->renderToImage($list, self::W, self::H, $clear);

        if ($mrt === '1' && !$gated->mrtActive()) {
            $this->markTestSkipped('the MRT path did not run on this backend');
        }
        self::assertSame($mrt === '1', $gated->mrtActive(), 'the path asked for ran');
        self::assertSame(ProcModeShaderRegistry::NOGRID_VARIANT, $gated->frameBaseProgramVariant());
        self::assertSame(md5($oldImage), md5($image));
    }

    /** @return array<string, array{0: string}> */
    public static function mrtPaths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    private function context(string $backend): void
    {
        $cache = sys_get_temp_dir() . '/phpolygon-test-shader-cache';
        $options = ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false, 'shader_cache' => $cache];
        $adapter = getenv('PHPOLYGON_TEST_VIO_ADAPTER');
        if (is_string($adapter) && $adapter !== '') {
            $options['adapter'] = ctype_digit($adapter) ? (int) $adapter : $adapter;
        }
        $ctx = $backend === 'auto'
            ? VioTestContext::create(self::W, self::H, ['shader_cache' => $cache])
            : @vio_create($backend, $options);
        if ($ctx === null || $ctx === false) {
            $this->markTestSkipped("vio_create($backend, headless) unavailable");
        }
        if ($backend !== 'auto' && vio_backend_name($ctx) !== $backend) {
            vio_destroy($ctx);
            $this->markTestSkipped("$backend unavailable");
        }
        $this->ctx = $ctx;
    }

    private function newRenderer(?GraphicsSettings $settings = null): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings($settings ?? new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        if ($renderer->maxPointLights() <= 8) {
            $this->markTestSkipped('this backend has no light grid: the base program is the grid-less one already');
        }
        return $renderer;
    }

    private function scene(int $pointLights): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(2.5, 4.0, 6.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.4, 0.4, 0.45), 0.1));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.3), new Color(1.0, 0.95, 0.9), 1.0));
        for ($i = 0; $i < $pointLights; $i++) {
            $list->add(new AddPointLight(new Vec3(-3.0 + $i * 0.6, 2.5, 3.0 - ($i % 3)), new Color(1.0, 0.8 - 0.02 * $i, 0.6), 2.0, 9.0));
        }
        $list->add(new DrawMesh('floor', 'stone', Mat4::translation(0.0, -1.1, 0.0)));
        $list->add(new DrawMesh('box', 'stone', Mat4::identity(), params: [0.0, 0.0, 0.0]));
        $list->add(new DrawMesh('box', 'leaf', Mat4::translation(3.0, 0.0, 0.0), params: [1.0, 0.0, 0.0]));
        return $list;
    }
}
