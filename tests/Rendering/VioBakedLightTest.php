<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Fieldtracing\Bake\BakedLightBaker;
use PHPolygon\Fieldtracing\Bake\SdfVolumeBaker;
use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetBakedLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Baked light (shader plan 3.3, GraphicsSettings::$bakedLight): a box on a floor, the
 * light volume baked from the box's SDF with the sun from +x. The lookup darkens the
 * floor on the box's shadow side (-x, left in the image) far more than on the sun
 * side and leaves the floor away from the box alone. With the live sun turned away
 * from the baked one only the sky term stays - left and right alike. With the setting
 * off the frame equals one without the command byte for byte. Forward and MRT; where
 * the G-buffer effects do not run (OpenGL) the lookup stays off and changes nothing.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioBakedLightTest extends TestCase
{
    private const W = 160;
    private const H = 120;

    private ?\VioContext $ctx = null;
    private static ?SetBakedLight $baked = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_TEXTURE_3D') || !vio_supports_feature($ctx, VIO_FEATURE_TEXTURE_3D)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no 3D textures');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(20.0, 0.2, 20.0));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.9, useEnvironmentMap: false));
        MaterialRegistry::register('box', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 0.9, useEnvironmentMap: false));
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

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    private static function baked(): SetBakedLight
    {
        if (self::$baked === null) {
            // The floor's top is y = -1, the box (1 m) stands on it; the field knows the box.
            $sdf = SdfVolumeBaker::bake(new BoxSdf(new Vec3(1.0, 1.0, 1.0), new Vec3(0.0, -0.5, 0.0)), new Vec3(-3.0, -1.2, -3.0), new Vec3(3.0, 1.5, 3.0), 32);
            self::$baked = SetBakedLight::fromVolume(BakedLightBaker::bake($sdf, new Vec3(1.0, 1.0, 0.0), 3.0, 8.0), 1);
        }
        return self::$baked;
    }

    private static function scene(bool $withVolume, Vec3 $sunDirection): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.2, 4.0), new Vec3(0.0, -2.4, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.25));
        $list->add(new SetDirectionalLight($sunDirection, new Color(1.0, 1.0, 1.0), 0.6));
        $list->add(new DrawMesh('floor', 'floor', Mat4::translation(0.0, -1.1, 0.0)));
        $list->add(new DrawMesh('box', 'box', Mat4::translation(0.0, -0.5, 0.0)));
        if ($withVolume) {
            $list->add(self::baked());
        }
        return $list;
    }

    /** @param-out VioRenderer3D $renderer */
    private function frame(bool $setting, bool $withVolume, Vec3 $sunDirection, ?VioRenderer3D &$renderer = null): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            ambientOcclusion: ScreenSpaceAO::Off,
            fieldtracing: FieldtracingMode::ProbesOnly,
            bakedLight: $setting,
        ));
        $list = self::scene($withVolume, $sunDirection);
        for ($i = 0; $i < 3; $i++) {
            vio_begin($this->ctx);
            $renderer->beginFrame();
            $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($this->ctx);
        }
        $rgba = (string) vio_read_pixels($this->ctx);
        self::assertSame(self::W * self::H * 4, strlen($rgba));
        return $rgba;
    }

    private static function luma(string $rgba, int $x, int $y): float
    {
        $o = ($y * self::W + $x) * 4;
        return 0.2126 * ord($rgba[$o]) + 0.7152 * ord($rgba[$o + 1]) + 0.0722 * ord($rgba[$o + 2]);
    }

    /** @return array{left: float, right: float, corners: int} summed darkening left / right of the box, darkened corner pixels */
    private static function darkening(string $off, string $on): array
    {
        $left = 0.0;
        $right = 0.0;
        $corners = 0;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $d = self::luma($off, $x, $y) - self::luma($on, $x, $y);
                if ($d <= 2.0) {
                    continue;
                }
                if (($x < 12 || $x >= self::W - 12) && ($y < 12 || $y >= self::H - 12)) {
                    $corners++;
                }
                if ($x < self::W / 2) {
                    $left += $d;
                } else {
                    $right += $d;
                }
            }
        }
        return ['left' => $left, 'right' => $right, 'corners' => $corners];
    }

    #[DataProvider('paths')]
    public function testTheLookupShadesTheShadowSideOfTheBox(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $sun = new Vec3(-1.0, -1.0, 0.0); // light travels toward -x: the sun stands at +x
        $off = $this->frame(false, true, $sun);
        $without = $this->frame(true, false, $sun);
        self::assertSame(md5($off), md5($without), 'setting off = no volume: the same frame');

        $on = $this->frame(true, true, $sun, $renderer);
        if (!$renderer->bakedLightActive()) {
            self::assertSame(md5($off), md5($on), 'without the lookup the frame is unchanged');
            self::assertNotNull($this->ctx);
            self::assertSame('opengl', vio_backend_name($this->ctx), 'only OpenGL goes without the G-buffer effects');
            return;
        }

        $d = self::darkening($off, $on);
        $label = sprintf('left %.0f, right %.0f, corners %d', $d['left'], $d['right'], $d['corners']);
        self::assertGreaterThan(3000.0, $d['left'], "{$label}: the shadow side darkens");
        self::assertGreaterThan($d['right'] * 2.0, $d['left'], "{$label}: far more than the sun side");
        self::assertLessThan(10, $d['corners'], "{$label}: the floor away from the box stays");
    }

    #[DataProvider('paths')]
    public function testASunAwayFromTheBakedOneKeepsOnlyTheSky(string $mrt): void
    {
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $sun = new Vec3(1.0, -1.0, 0.0); // the live sun stands at -x, 90 degrees off the baked one
        $off = $this->frame(false, true, $sun);
        $on = $this->frame(true, true, $sun, $renderer);
        if (!$renderer->bakedLightActive()) {
            self::assertSame(md5($off), md5($on));
            return;
        }
        $d = self::darkening($off, $on);
        $label = sprintf('left %.0f, right %.0f', $d['left'], $d['right']);
        self::assertGreaterThan(200.0, $d['left'] + $d['right'], "{$label}: the sky term still darkens the foot");
        // No baked shadow toward -x: the darkening is no longer one-sided.
        self::assertLessThan(max($d['right'], 200.0) * 2.0, $d['left'], "{$label}: not one-sided any more");
    }
}
