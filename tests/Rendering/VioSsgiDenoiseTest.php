<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Screen-space GI: the denoised half-resolution gather leaves no sparkle on a smooth wall (the share of
 * pixels that differ from their four neighbours by more than 3 levels), where the old inline gather did,
 * and still adds light (the image with GI is brighter than without).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSsgiDenoiseTest extends TestCase
{
    private const W = 480;
    private const H = 270;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        putenv('PHPOLYGON_VIO_MRT=1');
        putenv('PHPOLYGON_SSAO=1');
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('ball', SphereMesh::generate(0.5, 32, 48));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.55, 0.55, 0.55), roughness: 0.8, useEnvironmentMap: false));
        MaterialRegistry::register('wall', new Material(albedo: new Color(0.7, 0.6, 0.5), roughness: 0.9, useEnvironmentMap: false));
        MaterialRegistry::register('red', new Material(albedo: new Color(0.8, 0.2, 0.1), roughness: 0.3, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        putenv('PHPOLYGON_SSAO');
        putenv('PHPOLYGON_SSGI_DENOISE');
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private function frame(?bool $gi, bool $denoise): string
    {
        self::assertNotNull($this->ctx);
        putenv('PHPOLYGON_SSGI_DENOISE=' . ($denoise ? '1' : '0'));
        $r = new VioRenderer3D($this->ctx, self::W, self::H);
        $r->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Medium, ambientOcclusion: ScreenSpaceAO::High, antiAliasing: AntiAliasing::Off, bloom: false, screenSpaceGi: $gi === true));
        $img = '';
        for ($k = 0; $k < 2; $k++) {
            $l = new RenderCommandList();
            $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 2.2, 6.0), new Vec3(0.0, 0.6, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(0.9, self::W / self::H, 0.1, 60.0)));
            $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
            $l->add(new SetDirectionalLight((new Vec3(-0.4, -1.0, -0.5))->normalize(), new Color(1.0, 0.95, 0.85), 2.0));
            $l->add(new DrawMesh('box', 'floor', Mat4::translation(0.0, -0.05, 0.0)->multiply(Mat4::scaling(14.0, 0.1, 14.0))));
            $l->add(new DrawMesh('box', 'wall', Mat4::translation(0.0, 1.5, -3.0)->multiply(Mat4::scaling(14.0, 3.0, 0.2))));
            foreach ([-2.0, 0.0, 2.0] as $i => $x) {
                $l->add(new DrawMesh('ball', $i === 1 ? 'red' : 'wall', Mat4::translation($x, 0.5, 0.0)));
            }
            $img = $r->renderToImage($l, self::W, self::H, new Color(0.05, 0.06, 0.08));
        }

        return $img;
    }

    private static function lum(string $img, int $x, int $y): float
    {
        $o = ($y * self::W + $x) * 4;

        return ord($img[$o]) * 0.3 + ord($img[$o + 1]) * 0.59 + ord($img[$o + 2]) * 0.11;
    }

    /** Percentage of pixels in the rows $r0..$r1 that differ from their 4 neighbours' mean by more than 3 levels. */
    private static function sparkle(string $img, float $r0, float $r1): float
    {
        $n = 0;
        $hf = 0;
        for ($y = (int) (self::H * $r0); $y < (int) (self::H * $r1); $y++) {
            for ($x = (int) (self::W * 0.05); $x < (int) (self::W * 0.95); $x++) {
                $m = (self::lum($img, $x - 1, $y) + self::lum($img, $x + 1, $y) + self::lum($img, $x, $y - 1) + self::lum($img, $x, $y + 1)) / 4.0;
                if (abs(self::lum($img, $x, $y) - $m) > 3.0) {
                    $hf++;
                }
                $n++;
            }
        }

        return 100.0 * $hf / max(1, $n);
    }

    public function testTheDenoisedGatherHasNoSparkleOnASmoothWall(): void
    {
        $off = $this->frame(false, true);
        $old = $this->frame(true, false);
        $new = $this->frame(true, true);

        // The wall: rows 25 % to 40 % of the image (measured on D3D12 hardware: off 0.6 %, old 15.1 %, new 0.5 %).
        $sOff = self::sparkle($off, 0.25, 0.4);
        $sOld = self::sparkle($old, 0.25, 0.4);
        $sNew = self::sparkle($new, 0.25, 0.4);
        fwrite(STDERR, sprintf("[ssgi] sparkle on the wall: off %.1f %%, old gather %.1f %%, denoised %.1f %%\n", $sOff, $sOld, $sNew));
        self::assertGreaterThan(5.0, $sOld, 'the inline gather sparkles (the premise of the fix)');
        self::assertLessThan(max(2.0, $sOff * 2.0), $sNew, 'the denoised gather is as smooth as no GI');
        $bright = static function (string $img): float {
            $s = 0.0;
            for ($i = 0; $i < strlen($img); $i += 4) {
                $s += ord($img[$i]) + ord($img[$i + 1]) + ord($img[$i + 2]);
            }

            return $s;
        };
        self::assertGreaterThan($bright($off) * 1.002, $bright($new), 'GI still adds light');
    }
}
