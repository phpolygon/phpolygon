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
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
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
 * SSAO at a concave interior corner (two walls meeting at a right angle, a lamp in the room).
 * The corner line is vertical, so the true occlusion is the same all along it: whatever the
 * blurred AO target varies by from row to row at a fixed distance from the crease is noise
 * of the rotated kernel that the blur failed to average out (a dark mottled seam in the frame).
 * The metric is the mean standard deviation over the rows of each column within 10 AO texels
 * of the crease. Reads the blurred AO target the mesh shader samples (half resolution).
 * PHPOLYGON_TEST_VIO_BACKEND / _ADAPTER pick the context; the OpenGL backend keeps the surrogates.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[Group('gpu')]
final class VioSsaoConcaveCornerTest extends TestCase
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
        MeshRegistry::register('wall_back', BoxMesh::generate(8.2, 6.0, 0.2));
        MeshRegistry::register('wall_side', BoxMesh::generate(0.2, 6.0, 8.0));
        MaterialRegistry::register('plaster', new Material(albedo: new Color(0.7, 0.6, 0.45), roughness: 0.9, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /**
     * Mean row-to-row standard deviation of the AO near the crease, and the mean darkening
     * on the crease columns (the corner must stay darker than the open wall).
     *
     * @return array{0: float, 1: float}
     */
    private function cornerAo(string $mrt, ScreenSpaceAO $tier, bool $temporal): array
    {
        self::assertNotNull($this->ctx);
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $list = new RenderCommandList();
        // The crease is the vertical line x = 2, z = -4; the camera looks at it from inside the room.
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(-0.5, 0.0, 0.5), new Vec3(2.0, 0.0, -4.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.6, 0.65, 0.9), 0.35));
        $list->add(new AddPointLight(new Vec3(0.0, 2.0, -1.0), new Color(1.0, 0.85, 0.6), 4.0, 12.0));
        $list->add(new DrawMesh('wall_back', 'plaster', Mat4::translation(-2.0, 0.0, -4.1)));
        $list->add(new DrawMesh('wall_side', 'plaster', Mat4::translation(2.1, 0.0, 0.0)));

        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            ambientOcclusion: $tier,
            temporalSsao: $temporal,
        ));
        for ($i = 0; $i < 6; $i++) {
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

        // Crease column: where the AO darkens most along the middle rows.
        $row0 = intdiv($h, 4);
        $row1 = $h - $row0;
        $colMean = [];
        for ($x = 0; $x < $w; $x++) {
            $sum = 0.0;
            for ($y = $row0; $y < $row1; $y++) {
                $sum += ord($ao[($y * $w + $x) * 4]) / 255.0;
            }
            $colMean[$x] = $sum / ($row1 - $row0);
        }
        $crease = array_search(min($colMean), $colMean, true);
        self::assertIsInt($crease);

        $std = 0.0;
        $cols = 0;
        for ($x = max(0, $crease - 10); $x <= min($w - 1, $crease + 10); $x++) {
            $mean = $colMean[$x];
            $var = 0.0;
            $n = 0;
            for ($y = $row0; $y < $row1; $y++) {
                $o = ($y * $w + $x) * 4;
                if (ord($flags[$o + 1]) < 128) {
                    continue;
                }
                $d = ord($ao[$o]) / 255.0 - $mean;
                $var += $d * $d;
                $n++;
            }
            if ($n > 0) {
                $std += sqrt($var / $n);
                $cols++;
            }
        }
        self::assertGreaterThan(10, $cols, 'the corner is in view');
        $open = $colMean[max(0, $crease - 30)];

        return [$std / $cols, $open - $colMean[$crease]];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function paths(): array
    {
        return ['forward' => ['0', false], 'mrt' => ['1', false], 'mrt temporal' => ['1', true]];
    }

    #[DataProvider('paths')]
    public function testTheCornerSeamIsNotNoisy(string $mrt, bool $temporal): void
    {
        [$noise, $depth] = $this->cornerAo($mrt, ScreenSpaceAO::Medium, $temporal);
        $label = sprintf('%s: row-to-row AO noise %.5f, corner darkening %.4f', vio_backend_name($this->ctx), $noise, $depth);
        self::assertLessThan(0.012, $noise, $label);
        // The fix must not flatten the contact shadow itself.
        self::assertGreaterThan(0.05, $depth, $label);
    }
}
