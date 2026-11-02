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
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\InstanceParams;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * One material id shows different values per material copy, per draw and per
 * instance. Instances stay where their matrix puts them, in the colour pass, the
 * G-buffer and the shadow map: a value left in m[0..2].w would throw them off.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioInstanceParamsRenderTest extends TestCase
{
    private const W = 96;
    private const H = 32;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = @vio_create('auto', [
            'width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false,
            'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache',
        ]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('floor', BoxMesh::generate(30.0, 0.1, 30.0));
        MaterialRegistry::register('painted', new Material(albedo: new Color(1.0, 1.0, 1.0)));
        MaterialRegistry::register('painted_blue', (new Material(albedo: new Color(1.0, 1.0, 1.0)))->withParams(['u_fx_blue' => 1.0]));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.5, 0.5, 0.5), roughness: 1.0));
        ProcModeRegistry::map('painted', 3);
        ProcModeShaderRegistry::enableInstanceParams();
        ProcModeShaderRegistry::declareMaterialParameter('u_fx_blue', 'float', 0.0);
        ProcModeShaderRegistry::registerGlsl(
            3,
            '',
            "    if (u_proc_mode == 3) {\n"
            . "        frag_color = outputColor(vec3(v_instance_params.x, v_instance_params.z, u_fx_blue), 1.0);\n"
            . "        return;\n"
            . "    } else",
        );
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

    private static function list(float $params = 1.0): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -6.0), Mat4::perspective(deg2rad(40.0), self::W / self::H, 0.1, 100.0)));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, -0.2), new Color(1.0, 1.0, 1.0), 2.0));
        $list->add(new DrawMesh('floor', 'ground', Mat4::translation(0.0, -1.0, 0.0)));
        $list->add(new DrawMeshInstanced('box', 'painted', [
            InstanceParams::matrix(Mat4::translation(-3.0, 0.0, 0.0), $params, 0.25 * $params, 0.0),
            InstanceParams::matrix(Mat4::translation(-1.0, 0.0, 0.0), 0.0, 0.25 * $params, $params),
        ]));
        $list->add(new DrawMesh('box', 'painted', Mat4::translation(1.0, 0.0, 0.0), params: [1.0, 0.0, 1.0]));
        $list->add(new DrawMesh('box', 'painted_blue', Mat4::translation(3.0, 0.0, 0.0)));
        return $list;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testValuesReachTheirDrawAndInstancesStayInPlace(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::High,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $rgba = $renderer->renderToImage(self::list(), self::W, self::H, new Color(0.0, 0.0, 0.0, 1.0));
        self::assertSame($mrt === '1', $renderer->mrtActive());

        $at = static fn (float $x): array => self::px($rgba, (int) round((1.0 + $x * 1.0 / (6.0 * tan(deg2rad(20.0)) * self::W / self::H)) * self::W / 2.0), self::H >> 1);

        $a = $at(-3.0);
        self::assertGreaterThan(150, $a[0], 'first instance, x');
        self::assertLessThan(30, $a[1], 'first instance, z');

        $b = $at(-1.0);
        self::assertLessThan(30, $b[0], 'second instance, x');
        self::assertGreaterThan(150, $b[1], 'second instance, z');

        $c = $at(1.0);
        self::assertGreaterThan(150, $c[0], 'single draw');
        self::assertGreaterThan(150, $c[1], 'single draw');
        self::assertLessThan(30, $c[2], 'the material default');

        $d = $at(3.0);
        self::assertGreaterThan(150, $d[2], 'same proc mode, the copy with its own value');
        self::assertLessThan(30, $d[0], 'no params: zeros, not the last draw\'s');
    }

    /**
     * Floor rows only: shadow and AO of the instances, unaffected by what rides in
     * their matrices. A value left there would move them in the shadow map and G-buffer.
     */
    #[DataProvider('paths')]
    public function testParamsLeaveShadowsAndOcclusionAlone(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::High,
            ambientOcclusion: ScreenSpaceAO::Medium,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $with = $renderer->renderToImage(self::list(1.0), self::W, self::H, new Color(0.0, 0.0, 0.0, 1.0));
        $without = $renderer->renderToImage(self::list(0.0), self::W, self::H, new Color(0.0, 0.0, 0.0, 1.0));

        $rows = 4;
        $tail = $rows * self::W * 4;
        self::assertNotSame(md5($with), md5($without), 'the boxes themselves differ');
        self::assertSame(md5(substr($without, -$tail)), md5(substr($with, -$tail)), 'floor pixels');
        self::assertGreaterThan(0, array_sum(self::px($with, self::W >> 1, self::H - 1)) - 255, 'the floor is in the picture');
    }

    /** @return list<int> */
    private static function px(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, ($y * self::W + $x) * 4, 4)));
    }
}
