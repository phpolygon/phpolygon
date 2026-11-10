<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
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
use PHPolygon\System\ParticleColor;

/**
 * Material::$particleTint: the packed RGBA6 in v_instance_params.x tints the
 * particle in mesh3d.frag. Opaque colours (alpha 1, packed value above 2^23) keep
 * their hue - red stays red, white stays white - and the dark control keeps black.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioParticleTintRenderTest extends TestCase
{
    private const W = 160;
    private const H = 32;

    /** Instance x positions and their tints (r, g, b, a). */
    private const TINTS = [
        [-4.5, [1.0, 0.0, 0.0, 1.0]],
        [-1.5, [1.0, 1.0, 1.0, 1.0]],
        [1.5, [1.0, 0.9, 0.6, 1.0]],
        [4.5, [0.0, 1.0, 0.0, 1.0]],
    ];

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
        MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        // Emission carries the colour: the tint multiplies it, lighting plays no part.
        MaterialRegistry::register('spark', new Material(
            albedo: new Color(0.0, 0.0, 0.0),
            emission: new Color(1.0, 1.0, 1.0),
            particleTint: true,
        ));
        ProcModeShaderRegistry::enableInstanceParams();
    }

    protected function tearDown(): void
    {
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testOpaqueTintsKeepTheirHue(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -12.0), Mat4::perspective(deg2rad(30.0), self::W / self::H, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.0));
        $matrices = [];
        foreach (self::TINTS as [$x, [$r, $g, $b, $a]]) {
            $matrices[] = InstanceParams::matrix(Mat4::translation($x, 0.0, 0.0), ParticleColor::pack($r, $g, $b, $a));
        }
        $list->add(new DrawMeshInstanced('box', 'spark', $matrices));
        $rgba = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0, 1.0));

        $dump = getenv('PHPOLYGON_TEST_DUMP_DIR');
        if (is_string($dump) && $dump !== '' && function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(self::W, self::H);
            for ($y = 0; $y < self::H; $y++) {
                for ($x = 0; $x < self::W; $x++) {
                    [$pr, $pg, $pb] = self::px($rgba, $x, $y);
                    imagesetpixel($img, $x, $y, ($pr << 16) | ($pg << 8) | $pb);
                }
            }
            $backend = getenv('PHPOLYGON_TEST_VIO_BACKEND') ?: 'auto';
            imagepng($img, "{$dump}/particle-tint-{$backend}.png");
        }

        $at = static fn (float $x): array => self::px($rgba, (int) round((1.0 + $x / (12.0 * tan(deg2rad(15.0)) * self::W / self::H)) * self::W / 2.0), self::H >> 1);
        $seen = [];
        foreach (self::TINTS as [$x]) {
            $seen[] = implode(',', array_slice($at($x), 0, 3));
        }
        $where = ' (red, white, warm, green: ' . implode(' | ', $seen) . ')';

        [$r, $g, $b] = $at(-4.5);
        self::assertGreaterThan(150, $r, 'red keeps its red' . $where);
        self::assertLessThan(40, $g, 'red does not run into green' . $where);
        self::assertLessThan(40, $b, 'red' . $where);

        [$r, $g, $b] = $at(-1.5);
        self::assertGreaterThan(150, min($r, $g, $b), 'white stays white' . $where);

        [$r, $g, $b] = $at(1.5);
        self::assertGreaterThan(150, $r, 'warm keeps its red' . $where);
        self::assertGreaterThan($b, $g, 'warm: green above blue' . $where);
        self::assertGreaterThan($g, $r + 1, 'warm: red above green, not cyan' . $where);

        [$r, $g, $b] = $at(4.5);
        self::assertGreaterThan(150, $g, 'green control' . $where);
        self::assertLessThan(40, max($r, $b), 'green control' . $where);
    }

    /** @return list<int> */
    private static function px(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, ($y * self::W + $x) * 4, 4)));
    }
}
