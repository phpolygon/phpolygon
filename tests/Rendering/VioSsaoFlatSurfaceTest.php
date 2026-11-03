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
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * SSAO on a wide flat floor seen from high above: nothing occludes, so the AO target
 * must stay white. Every tap of the pass lands between G-buffer texels; filtered by
 * the sampler, the floor came out streaked along its rows (Metal, nearest) or speckled
 * (hardware bilinear, Vulkan and D3D12 on an RTX 2080: 14 % mean darkening at
 * 1280x720). Filtered in the shader it is white on every backend. Reads the blurred AO
 * target the mesh shader samples. PHPOLYGON_TEST_VIO_BACKEND / _HARDWARE pick the context;
 * the defect needs a hardware sampler: WARP filters precisely enough (0.05 % darkening
 * without the shader filter, measured 2026-11-03), so there the test proves only that
 * the filtered pass compiles and stays white.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSsaoFlatSurfaceTest extends TestCase
{
    private const W = 640;
    private const H = 360;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (vio_backend_name($ctx) === 'opengl') {
            vio_destroy($ctx);
            $this->markTestSkipped('the OpenGL backend keeps the forward surrogates');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        // Thick, so its bottom face never fights the top for depth at a distance.
        MeshRegistry::register('floor', BoxMesh::generate(6000.0, 100.0, 6000.0));
        MaterialRegistry::register('floor_matte', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.9, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        putenv('PHPOLYGON_SSAO_FILTER');
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /**
     * Mean darkening (1 - AO) over the floor and the mean step between the mean AO
     * of neighbouring rows (streaks), from the blurred AO target.
     *
     * @return array{0: float, 1: float}
     */
    private function floorAo(string $mrt): array
    {
        self::assertNotNull($this->ctx);
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 150.0, 0.0), new Vec3(0.0, 0.0, -400.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 3000.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.5));
        $list->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.2), new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new DrawMesh('floor', 'floor_matte', Mat4::translation(0.0, -50.0, 0.0)));

        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: true,
            ambientOcclusion: ScreenSpaceAO::High,
        ));
        for ($i = 0; $i < 2; $i++) {
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($this->ctx);
        }
        $raw = (new \ReflectionProperty(VioRenderer3D::class, 'ssaoTarget'))->getValue($renderer);
        $blur = (new \ReflectionProperty(VioRenderer3D::class, 'ssaoBlurTarget'))->getValue($renderer);
        self::assertInstanceOf(\VioRenderTarget::class, $raw, 'SSAO ran');
        self::assertInstanceOf(\VioRenderTarget::class, $blur);
        $flags = (string) vio_read_render_target($raw);
        $ao = (string) vio_read_render_target($blur);
        $w = intdiv(self::W, 2);
        $h = intdiv(self::H, 2);
        self::assertSame($w * $h * 4, strlen($ao));

        $dark = 0.0;
        $n = 0;
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $sum = 0.0;
            $count = 0;
            for ($x = 0; $x < $w; $x++) {
                $o = ($y * $w + $x) * 4;
                if (ord($flags[$o + 1]) < 128) {
                    continue; // sky
                }
                $a = ord($ao[$o]) / 255.0;
                $dark += 1.0 - $a;
                $sum += $a;
                $count++;
            }
            $n += $count;
            if ($count === $w) {
                $rows[] = $sum / $count;
            }
        }
        self::assertGreaterThan($w * $h / 3, $n, 'the floor fills the lower part of the frame');
        $step = 0.0;
        for ($i = 1; $i < count($rows); $i++) {
            $step += abs($rows[$i] - $rows[$i - 1]);
        }

        return [$dark / $n, count($rows) > 1 ? $step / (count($rows) - 1) : 0.0];
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testTheShaderFilterLeavesAFlatFloorUnoccluded(string $mrt): void
    {
        putenv('PHPOLYGON_SSAO_FILTER=1');
        [$dark, $step] = $this->floorAo($mrt);
        $label = sprintf('%s: mean darkening %.5f, row step %.5f', vio_backend_name($this->ctx), $dark, $step);
        self::assertLessThan(0.005, $dark, $label);
        self::assertLessThan(0.002, $step, $label);
    }

    /** What a game gets by default: the shader filter wherever ssaoFiltersInShader() says so. */
    public function testTheDefaultFilterOfTheBackend(): void
    {
        self::assertNotNull($this->ctx);
        $backend = vio_backend_name($this->ctx);
        if (!VioRenderer3D::ssaoFiltersInShader($backend)) {
            $this->markTestSkipped("{$backend} keeps the sampler (frames of v0.96.1)");
        }
        [$dark, $step] = $this->floorAo('0');
        self::assertLessThan(0.005, $dark, sprintf('%s: mean darkening %.5f', $backend, $dark));
        self::assertLessThan(0.002, $step, sprintf('%s: row step %.5f', $backend, $step));
    }
}
