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
 * Aurora (SetSky::$aurora): green light appears in the dark night sky above the
 * horizon, nothing below it, and it drifts over time; also without stars.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioAuroraTest extends TestCase
{
    private const W = 96;
    private const H = 64;

    public function testTheAuroraGlowsGreenInTheNightSkyAndDrifts(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $shot = fn (float $aurora, float $time) => $renderer->renderToImage($this->scene($aurora, $time), self::W, self::H, new Color(0.0, 0.0, 0.0));
            $dark = $shot(0.0, 10.0);
            $lit = $shot(1.0, 10.0);
            $later = $shot(1.0, 70.0);
            [$skyG0, $skyR0] = $this->sky($dark, intdiv(self::H, 4), intdiv(self::H * 3, 5));
            [$skyG1, $skyR1] = $this->sky($lit, intdiv(self::H, 4), intdiv(self::H * 3, 5));
            [$lowG0] = $this->sky($dark, intdiv(self::H * 4, 5), self::H);
            [$lowG1] = $this->sky($lit, intdiv(self::H * 4, 5), self::H);
            $drift = 0;
            for ($i = 0, $n = strlen($lit); $i < $n; $i += 4) {
                $drift += abs(ord($lit[$i + 1]) - ord($later[$i + 1]));
            }
            $drift /= self::W * self::H;
            fprintf(STDERR, "[aurora] sky green %.1f -> %.1f (red %.1f -> %.1f), below the horizon %.1f -> %.1f, drift %.2f\n", $skyG0, $skyG1, $skyR0, $skyR1, $lowG0, $lowG1, $drift);
            self::assertGreaterThan($skyG0 + 6.0, $skyG1, 'green light in the sky');
            self::assertGreaterThan($skyR1 - $skyR0, $skyG1 - $skyG0, 'mostly green');
            self::assertEqualsWithDelta($lowG0, $lowG1, 0.5, 'nothing below the horizon');
            self::assertGreaterThan(0.5, $drift, 'the curtains drift');
        } finally {
            vio_destroy($ctx);
        }
    }

    private function scene(float $aurora, float $time): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 2.0, 0.0), new Vec3(0.0, 2.5, -1.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(80.0), self::W / self::H, 0.1, 1000.0)));
        $list->add(new SetSky(
            sunDirection: new Vec3(0.0, -1.0, 0.0),
            sunColor: new Color(1.0, 1.0, 1.0),
            sunIntensity: 0.0,
            zenithColor: new Color(0.005, 0.008, 0.02),
            horizonColor: new Color(0.01, 0.012, 0.025),
            groundColor: new Color(0.005, 0.005, 0.005),
            time: $time,
            aurora: $aurora,
        ));
        return $list;
    }

    /** @return array{0: float, 1: float} mean green and red of rows $y0..$y1 */
    private function sky(string $img, int $y0, int $y1): array
    {
        $g = 0;
        $r = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $o = ($y * self::W + $x) * 4;
                $r += ord($img[$o]);
                $g += ord($img[$o + 1]);
            }
        }
        $n = ($y1 - $y0) * self::W;
        return [$g / $n, $r / $n];
    }
}
