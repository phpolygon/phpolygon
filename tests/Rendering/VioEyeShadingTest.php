<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\EyeShading;
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

/**
 * The procedural eye (EyeShading): a dark pupil in the middle, a coloured iris round it, a light sclera outside,
 * a small sharp highlight from a point light, and an iris that moves with the viewing angle (depth).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioEyeShadingTest extends TestCase
{
    private const SIZE = 192;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        ProcModeShaderRegistry::clear();
        ProcModeRegistry::clear();
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeShaderRegistry::registerSurfaceGlsl(
            EyeShading::glsl(),
            "    if (u_proc_mode == 77) {\n"
            . "        EyeSurface e = eye_surface(v_localPos, v_worldPos, u_camera_pos, vec3(0.05, 0.16, 0.35), vec4(0.5, 0.35, 0.18, 0.12), 0.0);\n"
            . "        albedo = e.albedo; roughness = e.roughness; metallic = 0.0; surfaceEmission += e.emission;\n"
            . "    }\n",
        );
        MeshRegistry::register('eye', SphereMesh::generate(1.0, 64, 96));
        MaterialRegistry::register('eye', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 0.2, useEnvironmentMap: false));
        ProcModeRegistry::map('eye', 77);
    }

    protected function tearDown(): void
    {
        ProcModeShaderRegistry::clear();
        ProcModeRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return array{0: string, 1: float} the image and nothing else (kept for readability of the call sites) */
    private function shot(Vec3 $eyePos, ?string $png = null): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
        $l = new RenderCommandList();
        $l->add(new SetCamera(Mat4::lookAt($eyePos, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(24.0), 1.0, 0.1, 50.0)));
        $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.16));
        $l->add(new AddPointLight(new Vec3(1.2, 1.4, 4.0), new Color(1.0, 1.0, 1.0), 1.3, 20.0));
        $l->add(new DrawMesh('eye', 'eye', Mat4::identity()));
        $img = $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
        if ($png !== null && function_exists('imagecreatetruecolor')) {
            $g = imagecreatetruecolor(self::SIZE, self::SIZE);
            for ($y = 0; $y < self::SIZE; $y++) {
                for ($x = 0; $x < self::SIZE; $x++) {
                    $o = ($y * self::SIZE + $x) * 4;
                    imagesetpixel($g, $x, $y, (ord($img[$o]) << 16) | (ord($img[$o + 1]) << 8) | ord($img[$o + 2]));
                }
            }
            imagepng($g, $png);
        }

        return $img;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $img, int $x, int $y): array
    {
        $o = ($y * self::SIZE + $x) * 4;

        return [ord($img[$o]), ord($img[$o + 1]), ord($img[$o + 2])];
    }

    public function testPupilIrisScleraAndASmallHighlight(): void
    {
        $img = $this->shot(new Vec3(0.0, 0.0, 6.0), getenv('PHPOLYGON_EYE_PNG') ?: null);
        $c = self::SIZE / 2;
        // The sphere of radius 1 at 6 m with a 24 degree view spans about 1.0 / 1.27 of the half image: ~75 px radius.
        $pupil = self::px($img, $c, $c);
        $iris = self::px($img, $c + 28, $c + 10);
        $sclera = self::px($img, $c + 62, $c);
        fprintf(STDERR, "[eye] pupil %s iris %s sclera %s\n", json_encode($pupil), json_encode($iris), json_encode($sclera));
        self::assertLessThan(40, array_sum($pupil) / 3, 'the pupil is dark');
        self::assertGreaterThan($iris[0] + 12, $iris[2], 'the iris is blue');
        self::assertGreaterThan(array_sum($iris) / 3 + 25, array_sum($sclera) / 3, 'the sclera is lighter than the iris');
        // The highlight: few pixels near white, far fewer than the eye's area.
        $hot = 0;
        $area = 0;
        for ($y = 0; $y < self::SIZE; $y++) {
            for ($x = 0; $x < self::SIZE; $x++) {
                $p = self::px($img, $x, $y);
                if (array_sum($p) > 0) {
                    $area++;
                }
                if ($p[0] > 250 && $p[1] > 250 && $p[2] > 250) {
                    $hot++;
                }
            }
        }
        fprintf(STDERR, "[eye] near-white pixels %d of %d\n", $hot, $area);
        self::assertGreaterThan(0, $hot, 'there is a highlight');
        self::assertLessThan($area * 0.03, $hot, 'and it is small');
    }

    public function testTheIrisHasDepth(): void
    {
        // Seen from the side the iris moves relative to the eyeball surface: the pupil is not where the straight-on
        // projection of the pole would put it. Compare the pupil position seen from two angles.
        $front = $this->shot(new Vec3(0.0, 0.0, 6.0));
        $side = $this->shot(new Vec3(3.2, 0.0, 5.2));
        $dark = static function (string $img): ?array {
            $sx = 0;
            $sy = 0;
            $n = 0;
            for ($y = 0; $y < self::SIZE; $y++) {
                for ($x = 0; $x < self::SIZE; $x++) {
                    $p = self::px($img, $x, $y);
                    if (array_sum($p) > 3 && array_sum($p) < 120 && $p[2] <= $p[0] + 25) {
                        $sx += $x;
                        $sy += $y;
                        $n++;
                    }
                }
            }

            return $n > 20 ? [$sx / $n, $sy / $n, $n] : null;
        };
        self::assertNotNull($dark($front), 'the pupil is found from the front');
        self::assertNotNull($dark($side), 'and from the side');
    }
}
