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
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ShaderPath;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Moving a mode into a variant program, or a path out of a program that does not
 * need it, must not change one byte of the image, forward or MRT, opaque or
 * transparent, in draw orders that switch programs back and forth.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioShaderVariantRenderTest extends TestCase
{
    private const W = 96;
    private const H = 64;

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
        MeshRegistry::register('slab', BoxMesh::generate(8.0, 0.2, 8.0));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 0.8));
        MaterialRegistry::register('tint_red', new Material(albedo: new Color(0.8, 0.8, 0.8)));
        MaterialRegistry::register('tint_flat', new Material(albedo: new Color(0.8, 0.8, 0.8)));
        MaterialRegistry::register('flat_glass', new Material(albedo: new Color(0.3, 0.8, 0.9), alpha: 0.5));
        ProcModeRegistry::map('tint_red', 3);
        ProcModeRegistry::map('tint_flat', 5);
        ProcModeRegistry::map('flat_glass', 5);
        ProcModeShaderRegistry::registerGlsl(
            3,
            "vec3 tintRed(vec3 c) { return c * vec3(1.0, 0.2, 0.2); }\n",
            "    if (u_proc_mode == 3) {\n        albedo = tintRed(texAlbedo);\n",
        );
        ProcModeShaderRegistry::registerGlsl(
            5,
            '',
            "    } else if (u_proc_mode == 5) {\n        frag_color = outputColor(texAlbedo * 0.5, alpha);\n        return;\n    } else",
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

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testVariantProgramsLeaveTheImageUntouched(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $reference = $this->render();

        ProcModeShaderRegistry::defineVariant('tinted', [3], [ShaderPath::SurfacePatterns]);
        ProcModeShaderRegistry::defineVariant('unshaded', [5], [
            ShaderPath::NormalPatterns, ShaderPath::SurfacePatterns, ShaderPath::Grass, ShaderPath::Subsurface,
            ShaderPath::Clearcoat, ShaderPath::Snow, ShaderPath::Volumetric, ShaderPath::CloudShadow, ShaderPath::ProbeGi,
        ], lazy: true);
        ProcModeShaderRegistry::omitFromBase([ShaderPath::Clearcoat, ShaderPath::Grass]);
        $variants = $this->render();

        self::assertSame(md5($reference), md5($variants), 'every pixel the same with the modes in their own programs');
        // The scene really drew the three looks.
        self::assertNotSame(self::px($reference, 48, 32), self::px($reference, 12, 32));
        self::assertNotSame(self::px($reference, 48, 32), self::px($reference, 84, 32));
    }

    private function render(): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            bloom: false,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::translation(0.0, -0.5, -7.0),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.4, 0.4, 0.45), 0.5));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.3), new Color(1.0, 0.95, 0.9), 1.0));
        $list->add(new DrawMesh('slab', 'floor', Mat4::translation(0.0, -1.0, 0.0)));
        $list->add(new DrawMesh('box', 'tint_red', Mat4::translation(-2.2, 0.0, 0.0)));
        $list->add(new DrawMesh('box', 'floor', Mat4::translation(0.0, 0.0, 0.0)));
        $list->add(new DrawMesh('box', 'tint_flat', Mat4::translation(2.2, 0.0, 0.0)));
        $list->add(new DrawMesh('box', 'flat_glass', Mat4::translation(1.1, 0.2, 1.5)));
        $rgba = $renderer->renderToImage($list, self::W, self::H, new Color(0.1, 0.2, 0.4, 1.0));
        self::assertSame(self::W * self::H * 4, strlen($rgba));
        return $rgba;
    }

    /** @return list<int> */
    private static function px(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, (($y * self::W) + $x) * 4, 4)));
    }
}
