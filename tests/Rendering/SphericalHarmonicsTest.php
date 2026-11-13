<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\SphericalHarmonics;

final class SphericalHarmonicsTest extends TestCase
{
    /** @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> */
    private static function sphere(callable $radiance, int $n = 40): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $y = 1.0 - ($i + 0.5) / $n * 2.0;
            $r = sqrt(max(0.0, 1.0 - $y * $y));
            for ($j = 0; $j < $n * 2; $j++) {
                $phi = ($j + 0.5) / ($n * 2) * 2.0 * M_PI;
                $x = $r * cos($phi);
                $z = $r * sin($phi);
                [$cr, $cg, $cb] = $radiance($x, $y, $z);
                $out[] = [$x, $y, $z, $cr, $cg, $cb];
            }
        }

        return $out;
    }

    public function testAUniformSkyGivesTheFlatAmbientEverywhere(): void
    {
        $sh = SphericalHarmonics::project(self::sphere(static fn (): array => [0.5, 0.4, 0.3]));
        $c = $sh->irradianceCoefficients(1.0);
        foreach ([[0.0, 1.0, 0.0], [0.0, -1.0, 0.0], [1.0, 0.0, 0.0], [0.577, 0.577, 0.577]] as [$x, $y, $z]) {
            [$r, $g, $b] = SphericalHarmonics::evaluate($c, $x, $y, $z);
            // Uniform radiance L gives L * pi: the same scale as the flat ambient colour times its pi-scaled intensity.
            self::assertEqualsWithDelta(0.5 * M_PI, $r, 0.02);
            self::assertEqualsWithDelta(0.4 * M_PI, $g, 0.02);
            self::assertEqualsWithDelta(0.3 * M_PI, $b, 0.02);
        }
    }

    public function testASkyAboveLightsUpFacesAndDarkensTheUndersides(): void
    {
        $sh = SphericalHarmonics::project(self::sphere(static fn (float $x, float $y): array => $y > 0.0 ? [1.0, 1.0, 1.0] : [0.0, 0.0, 0.0]));
        $c = $sh->irradianceCoefficients(1.0);
        $up = SphericalHarmonics::evaluate($c, 0.0, 1.0, 0.0)[0];
        $side = SphericalHarmonics::evaluate($c, 1.0, 0.0, 0.0)[0];
        $down = SphericalHarmonics::evaluate($c, 0.0, -1.0, 0.0)[0];
        // Analytic irradiance / pi of a hemisphere of radiance 1: 1 facing it, 0.5 at the rim, 0 away (L2 truncation: close).
        self::assertEqualsWithDelta(M_PI, $up, 0.45);
        self::assertEqualsWithDelta(M_PI * 0.5, $side, 0.25);
        self::assertLessThan(0.45, $down);
        self::assertGreaterThan($side, $up);
    }
}
