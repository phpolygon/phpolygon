<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Lightning volume (shader plan 10.2): a flash inside a closed night cloud deck lights
 * the clouds around it from within - looking towards it the deck brightens far more
 * than looking away; cloud-to-cloud lightning draws no bolt below the clouds.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioLightningVolumeTest extends TestCase
{
    private const W = 96;
    private const H = 64;

    public function testTheFlashLightsTheCloudsAroundIt(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $look = fn (float $flash, float $yaw, bool $inCloud = true) => $this->upper($renderer->renderToImage($this->scene($flash, $yaw, $inCloud), self::W, self::H, new Color(0.0, 0.0, 0.0)));
            $towardsDark = $look(0.0, 0.0);
            $towardsLit = $look(1.0, 0.0);
            $awayDark = $look(0.0, M_PI);
            $awayLit = $look(1.0, M_PI);
            fprintf(STDERR, "[lightning] towards the flash %.1f -> %.1f, away %.1f -> %.1f\n", $towardsDark, $towardsLit, $awayDark, $awayLit);
            self::assertGreaterThan($towardsDark + 20.0, $towardsLit, 'the deck lights up around the flash');
            self::assertGreaterThan(2.0 * ($awayLit - $awayDark), $towardsLit - $towardsDark, 'more towards the flash than away from it');

            // Below the deck, towards the strike: a bolt with cloud-to-ground lightning only.
            $boltRow = $this->lower($renderer->renderToImage($this->scene(1.0, 0.0, false), self::W, self::H, new Color(0.0, 0.0, 0.0)));
            $sheetRow = $this->lower($renderer->renderToImage($this->scene(1.0, 0.0, true), self::W, self::H, new Color(0.0, 0.0, 0.0)));
            fprintf(STDERR, "[lightning] below the deck: bolt %.1f, cloud-to-cloud %.1f\n", $boltRow, $sheetRow);
            self::assertGreaterThan($sheetRow + 3.0, $boltRow, 'only the cloud-to-ground strike draws a bolt');
        } finally {
            vio_destroy($ctx);
        }
    }

    private function scene(float $flash, float $yaw, bool $inCloud): RenderCommandList
    {
        $list = new RenderCommandList();
        $dir = new Vec3(sin($yaw), 0.35, cos($yaw));
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 2.0, 0.0), new Vec3(sin($yaw), 2.35, cos($yaw)), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(70.0), self::W / self::H, 0.1, 2000.0)));
        unset($dir);
        $list->add(new SetSky(
            sunDirection: new Vec3(0.0, -1.0, 0.0),
            sunColor: new Color(1.0, 1.0, 1.0),
            sunIntensity: 0.0,
            zenithColor: new Color(0.01, 0.01, 0.02),
            horizonColor: new Color(0.02, 0.02, 0.03),
            groundColor: new Color(0.01, 0.01, 0.01),
            cloudCover: 0.95,
            cloudAltitude: 120.0,
            cloudDensity: 0.9,
            cloudDarkness: 1.0,
            lightning: $flash,
            lightningAzimuth: 0.0,
            lightningInCloud: $inCloud,
            lightningDistance: 300.0,
        ));
        return $list;
    }

    /** Mean brightness where the flash sits in the deck (the middle of the view). */
    private function upper(string $img): float
    {
        return $this->band($img, (int) (self::H * 0.38), (int) (self::H * 0.54), (int) (self::W * 0.35), (int) (self::W * 0.65));
    }

    /** Mean brightness below the flash, under the deck, where a bolt would run. */
    private function lower(string $img): float
    {
        return $this->band($img, (int) (self::H * 0.58), (int) (self::H * 0.72), (int) (self::W * 0.42), (int) (self::W * 0.58));
    }

    private function band(string $img, int $y0, int $y1, int $x0, int $x1): float
    {
        $sum = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $o = ($y * self::W + $x) * 4;
                $sum += ord($img[$o]) + ord($img[$o + 1]) + ord($img[$o + 2]);
            }
        }
        return $sum / (($y1 - $y0) * ($x1 - $x0) * 3);
    }
}
