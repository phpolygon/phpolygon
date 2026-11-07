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
 * Physical sky (SetSky::$physicalSky): with a grey authored gradient the colours
 * come from the atmosphere alone - a blue day sky that pales towards the horizon,
 * a red-orange horizon towards a setting sun under a still bluish sky; weight 0
 * keeps the authored grey.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioPhysicalSkyTest extends TestCase
{
    private const W = 64;
    private const H = 48;

    public function testTheAtmosphereColoursDayAndSunset(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $shot = fn (float $sunDeg, float $physical, float $az = 0.5) => $renderer->renderToImage($this->scene($sunDeg, $physical, $az), self::W, self::H, new Color(0.0, 0.0, 0.0));
            // Rows: the top sixth looks ~50-64 degrees up, rows 0.55-0.62 H just above the horizon.
            $high = [0, intdiv(self::H, 6)];
            $low = [(int) (self::H * 0.55), (int) (self::H * 0.62)];

            $grey = $shot(60.0, 0.0, M_PI);
            [$gr, , $gb] = $this->mean($grey, ...$high);
            // Day: the sun behind the camera, so its haze glow stays out of the view.
            $day = $shot(60.0, 1.0, M_PI);
            [$dr, $dg, $db] = $this->mean($day, ...$high);
            [$hr, $hg, $hb] = $this->mean($day, ...$low);
            $dusk = $shot(3.0, 1.0);
            [$sr, $sg, $sb] = $this->mean($dusk, ...$low, sunSide: true);
            [$zr, , $zb] = $this->mean($dusk, ...$high);
            fprintf(STDERR, "[physical sky] grey %.0f/%.0f; day high %.0f %.0f %.0f, low %.0f %.0f %.0f; dusk horizon %.0f %.0f %.0f, high r %.0f b %.0f\n",
                $gr, $gb, $dr, $dg, $db, $hr, $hg, $hb, $sr, $sg, $sb, $zr, $zb);

            self::assertEqualsWithDelta($gr, $gb, 3.0, 'weight 0 keeps the authored grey');
            self::assertGreaterThan($dr + 40.0, $db, 'the day sky is blue');
            self::assertLessThan($db - $dr, $hb - $hr, 'it pales towards the horizon');
            self::assertGreaterThan(($dr + $dg + $db) / 3.0, ($hr + $hg + $hb) / 3.0, 'the horizon is brighter');
            self::assertGreaterThan($sb + 60.0, $sr, 'the horizon glows red-orange towards the setting sun');
            self::assertGreaterThanOrEqual($zr, $zb, 'the sky above stays bluish at dusk');
        } finally {
            vio_destroy($ctx);
        }
    }

    private function scene(float $sunDeg, float $physical, float $az): RenderCommandList
    {
        $e = deg2rad($sunDeg);
        $list = new RenderCommandList();
        // Looking north (-Z), slightly up; azimuth 0.5 puts the sun right of the view centre, pi behind.
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 2.0, 0.0), new Vec3(0.0, 2.35, -1.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(90.0), self::W / self::H, 0.1, 1000.0)));
        $list->add(new SetSky(
            sunDirection: new Vec3(sin($az) * cos($e), sin($e), -cos($az) * cos($e)),
            sunColor: new Color(1.0, 0.95, 0.9),
            sunIntensity: 0.0,
            zenithColor: new Color(0.5, 0.5, 0.5),
            horizonColor: new Color(0.5, 0.5, 0.5),
            groundColor: new Color(0.3, 0.3, 0.3),
            physicalSky: $physical,
        ));
        return $list;
    }

    /** @return array{0: float, 1: float, 2: float} mean RGB of rows $y0..$y1 (right half only with $sunSide) */
    private function mean(string $img, int $y0, int $y1, bool $sunSide = false): array
    {
        $s = [0, 0, 0];
        $n = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $sunSide ? intdiv(self::W, 2) : 0; $x < self::W; $x++) {
                $o = ($y * self::W + $x) * 4;
                $s[0] += ord($img[$o]);
                $s[1] += ord($img[$o + 1]);
                $s[2] += ord($img[$o + 2]);
                $n++;
            }
        }
        return [$s[0] / $n, $s[1] / $n, $s[2] / $n];
    }
}
