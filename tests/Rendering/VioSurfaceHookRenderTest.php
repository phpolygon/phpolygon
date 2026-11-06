<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
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
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\SurfaceEffects;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The surface hook on the GPU: a draw can be cut away, glow through surfaceEmission
 * and change its albedo, on the forward and the MRT path; without a hook the frame
 * is the frame of the engine before the hook. Every SurfaceEffects function is
 * called once, so the library compiles on the backend under test.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSurfaceHookRenderTest extends TestCase
{
    private const W = 128;
    private const H = 32;

    private const HOOK = <<<'GLSL'
    float sfxAll = sfx_noise3(v_worldPos) + sfx_dissolve(0.5, 0.2, 0.1).y + sfx_front(1.0, 2.0, 3.0)
        + sfx_behind(1.0, 2.0, 0.5) + sfx_scanBuild(1.0, 2.0, 0.2).y + sfx_wireGrid(v_worldPos, N, 1.0, 1.0)
        + sfx_glitch(v_worldPos, 1.0, 0.5, 4.0) + sfx_pulses(1.0, 0.5, 2.0, 3.0, 0.2);
    float sfxT;
    sfxAll += sfx_segment(v_worldPos.xz, vec2(0.0), vec2(1.0), sfxT) + sfxT;
    vec3 sfxAlb = albedo;
    float sfxRough = roughness;
    sfx_weather(sfxAlb, sfxRough, v_worldPos, N, 0.0);
    if (sfxAll < -1.0e9 || sfxAlb.r < -1.0) {
        discard;
    }
#ifdef PHPOLYGON_SURFACE_CUT
    if (v_instance_params.x > 0.5) {
        discard;
    }
#endif
    if (v_instance_params.y > 0.5) {
        albedo = vec3(0.0);
        surfaceEmission = vec3(0.0, 3.0, 0.0);
    }
    if (v_instance_params.z > 0.5) {
        albedo = sfx_saturation(albedo, 0.0);
    }

GLSL;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('wall', BoxMesh::generate(40.0, 40.0, 0.1));
        MaterialRegistry::register('red', new Material(albedo: new Color(1.0, 0.1, 0.1), roughness: 1.0));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.1, 0.1, 0.8), roughness: 1.0));
    }

    protected function tearDown(): void
    {
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

    private static function list(): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -7.0), Mat4::perspective(deg2rad(40.0), self::W / self::H, 0.1, 100.0)));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -0.5, -1.0), new Color(1.0, 1.0, 1.0), 2.0));
        $list->add(new DrawMesh('wall', 'ground', Mat4::translation(0.0, 0.0, -6.0), params: [0.0, 0.0, 0.0]));
        $list->add(new DrawMesh('box', 'red', Mat4::translation(-3.0, 0.0, 0.0), params: [1.0, 0.0, 0.0]));
        $list->add(new DrawMesh('box', 'red', Mat4::translation(-1.0, 0.0, 0.0), params: [0.0, 1.0, 0.0]));
        $list->add(new DrawMesh('box', 'red', Mat4::translation(1.0, 0.0, 0.0), params: [0.0, 0.0, 1.0]));
        $list->add(new DrawMesh('box', 'red', Mat4::translation(3.0, 0.0, 0.0), params: [0.0, 0.0, 0.0]));
        return $list;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    private function render(string $mrt): string
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            // High, not Off: on the vio OpenGL backend Off draws nothing at all (engine bug,
            // independent of the hook, see the package 1.1 report).
            shadowQuality: ShadowQuality::High,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $rgba = $renderer->renderToImage(self::list(), self::W, self::H, new Color(0.0, 0.0, 0.0, 1.0));
        if ($mrt === '1' && !$renderer->mrtActive()) {
            $this->markTestSkipped('the MRT path needs G-buffer consumers, which are Direct3D only');
        }
        return $rgba;
    }

    #[DataProvider('paths')]
    public function testCutGlowAndAlbedoReachTheirDraw(string $mrt): void
    {
        ProcModeShaderRegistry::enableInstanceParams();
        ProcModeShaderRegistry::registerSurfaceGlsl(SurfaceEffects::glsl(), self::HOOK, '', '');
        ProcModeShaderRegistry::setSurfaceCut(true);
        $rgba = $this->render($mrt);

        $glow = $this->at($rgba, -1.0);
        self::assertGreaterThan(150, $glow[1], 'surfaceEmission');
        self::assertLessThan(60, $glow[0], 'albedo set to black');

        $grey = $this->at($rgba, 1.0);
        self::assertLessThan(12, abs($grey[0] - $grey[1]), 'saturation 0: grey');
        self::assertLessThan(12, abs($grey[1] - $grey[2]), 'saturation 0: grey');

        $plain = $this->at($rgba, 3.0);
        self::assertGreaterThan($plain[1] + 60, $plain[0], 'untouched draw stays red');

        // Whatever is behind it (the wall, or the clear colour where a backend draws
        // the wall differently): not the red box.
        $cut = $this->at($rgba, -3.0);
        self::assertLessThan($plain[0] - 60, $cut[0], 'cut away');
    }

    /**
     * Without a running cut the base program has no discard (it would cost early depth
     * testing for every draw): the box the hook cuts away during a cut stays.
     */
    #[DataProvider('paths')]
    public function testDiscardOnlyWhileACutRuns(string $mrt): void
    {
        ProcModeShaderRegistry::enableInstanceParams();
        ProcModeShaderRegistry::registerSurfaceGlsl(SurfaceEffects::glsl(), self::HOOK, '', '');
        self::assertFalse(ProcModeShaderRegistry::surfaceCutActive());
        $rgba = $this->render($mrt);
        $plain = $this->at($rgba, 3.0);
        $kept = $this->at($rgba, -3.0);
        self::assertGreaterThan($kept[1] + 60, $kept[0], 'no cut running: the box stays red');
        self::assertLessThan(12, abs($kept[0] - $plain[0]), 'drawn like the untouched box');
    }

    /** A hook that does nothing leaves the frame of no hook, byte for byte. */
    #[DataProvider('paths')]
    public function testAnIdleHookChangesNothing(string $mrt): void
    {
        ProcModeShaderRegistry::enableInstanceParams();
        $without = $this->render($mrt);
        ProcModeShaderRegistry::registerSurfaceGlsl(SurfaceEffects::glsl(), "    surfaceEmission += vec3(0.0);\n", '', '');
        $with = $this->render($mrt);

        self::assertSame(md5($without), md5($with));
    }

    /** @return list<int> */
    private function at(string $rgba, float $x): array
    {
        $px = (int) round((1.0 + $x / (7.0 * tan(deg2rad(20.0)) * self::W / self::H)) * self::W / 2.0);
        return array_values((array) unpack('C4', substr($rgba, ((self::H >> 1) * self::W + $px) * 4, 4)));
    }
}
