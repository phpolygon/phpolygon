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
use PHPolygon\Rendering\Command\SetShaderParameter;
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
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;
use PHPolygon\Rendering\Water\WaterShading;
use PHPolygon\Terrain\CoastField;
use PHPolygon\Terrain\CoastTexture;

/**
 * The water library on the GPU: a snippet paints what `waterCoast()` and `waterWake()`
 * return on a 40 m square seen from straight above, and the picture has to match the
 * baked coast (orientation of the rows, mapping, decode) and the uploaded trail
 * (packing of the mat4 columns).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioWaterShadingTest extends TestCase
{
    private const SIZE = 64;
    private const HALF = 20.0;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('sea', BoxMesh::generate(2.0 * self::HALF, 0.1, 2.0 * self::HALF));
        MaterialRegistry::register('sea', new Material(albedo: new Color(1.0, 1.0, 1.0)));
        ProcModeRegistry::map('sea', 2);
        WaterShading::register();
        // Thresholds, so the tone curve cannot blur them: R = out at sea, G = deeper than 8 m,
        // B = wake foam above 0.3.
        ProcModeShaderRegistry::registerGlsl(
            2,
            '',
            "    if (u_proc_mode == 2) {\n"
            . "        vec3 c = waterCoast(v_worldPos.xz);\n"
            . "        vec3 w = waterWake(v_worldPos.xz, vec4(1.5, 0.354, 30.0, 0.05));\n"
            . "        frag_color = outputColor(vec3(step(0.0, c.x), step(8.0, c.y), step(0.3, w.x)), 1.0);\n"
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

    /** Land west of x = 0, the water deepening toward +z: 1 m per 2.5 m. */
    private static function coast(): CoastTexture
    {
        $h = self::HALF;
        $field = CoastField::waterline(static fn (float $x, float $z): float => $x < 0.0 ? 1.0 : -1.0, 0.0, -$h, $h, -$h, $h, 0.5);

        return (new CoastTexture(-$h, -$h, $h, $h, 80, 80, 16.0, 16.0, 16.0))
            ->addField($field)
            ->bake(static fn (float $x, float $z): float => ($z + $h) / 2.5);
    }

    private function scene(CoastTexture $coast, bool $wake): RenderCommandList
    {
        $list = new RenderCommandList();
        // Straight down, screen up = world -z, screen right = world +x.
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 30.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-self::HALF, self::HALF, -self::HALF, self::HALF, 1.0, 100.0),
        ));
        foreach (WaterShading::coastCommands($coast, 'coast') as $command) {
            $list->add($command);
        }
        // A trail along z = 0 from the head at x = +10 back to x = -10.
        $points = [];
        for ($i = 0; $i < 5; $i++) {
            $points[] = [10.0 - 5.0 * $i, 0.0, (float) $i, $i === 0 ? -1.0 : 1.0];
        }
        for ($k = 0; $k < WaterShading::WAKE_POINTS / 4; $k++) {
            $flat = array_fill(0, 16, 0.0);
            if ($wake && $k === 0) {
                foreach (array_slice($points, 0, 4) as $c => $p) {
                    array_splice($flat, $c * 4, 4, $p);
                }
            } elseif ($wake && $k === 1) {
                array_splice($flat, 0, 4, $points[4]);
            }
            $list->add(new SetShaderParameter(WaterShading::wakeUniform($k), $flat));
        }
        $list->add(new DrawMesh('sea', 'sea', Mat4::identity()));

        return $list;
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $textures = new VioTextureManager($this->ctx);
        $coast = self::coast();
        self::assertNotNull($textures->loadRgba('coast', $coast->width, $coast->height, $coast->rgba()));
        $renderer->setTextureManager($textures);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));

        return $renderer;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testTheCoastFieldLandsWhereItWasBaked(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $coast = self::coast();
        $image = $this->renderer()->renderToImage($this->scene($coast, false), self::SIZE, self::SIZE);

        // Off the edges by 3 m and more: the waterline at x = 0, depth 8 m at z = 0.
        foreach ([[-10.0, -10.0], [10.0, -10.0], [10.0, 10.0], [4.0, -4.0], [6.0, 4.0], [-3.0, 12.0], [15.0, 15.0]] as [$x, $z]) {
            $px = self::at($image, $x, $z);
            $cpu = $coast->sample($x, $z);
            self::assertSame($cpu['distance'] > 0.0, $px[0] > 127, "sea or land at ({$x}, {$z})");
            if ($cpu['distance'] > 0.0) {
                self::assertSame($cpu['depth'] > 8.0, $px[1] > 127, "deeper than 8 m at ({$x}, {$z})");
            }
        }
    }

    public function testTheWakeTrailsBehindTheHead(): void
    {
        putenv('PHPOLYGON_VIO_MRT=0');
        $image = $this->renderer()->renderToImage($this->scene(self::coast(), true), self::SIZE, self::SIZE);
        $without = $this->renderer()->renderToImage($this->scene(self::coast(), false), self::SIZE, self::SIZE);

        self::assertGreaterThan(80, self::at($image, 5.0, 0.0)[2], 'churned lane right behind the head');
        self::assertLessThan(10, self::at($image, 15.0, 0.0)[2], 'nothing ahead of the hull');
        self::assertLessThan(10, self::at($image, 5.0, 15.0)[2], 'nothing far to the side close to the hull');
        self::assertLessThan(5, self::at($without, 5.0, 0.0)[2], 'no trail uploaded, no wake');
    }

    /** @return list<int> RGBA of the pixel over world (x, z); row 0 is the top of the image (world -z). */
    private static function at(string $rgba, float $x, float $z): array
    {
        $col = (int) floor(($x + self::HALF) / (2.0 * self::HALF) * self::SIZE);
        $row = (int) floor(($z + self::HALF) / (2.0 * self::HALF) * self::SIZE);
        $col = max(0, min(self::SIZE - 1, $col));
        $row = max(0, min(self::SIZE - 1, $row));

        return array_values((array) unpack('C4', substr($rgba, ($row * self::SIZE + $col) * 4, 4)));
    }
}
