<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use PHPolygon\Audio\Dsp\Fft;
use PHPUnit\Framework\TestCase;

final class FftTest extends TestCase
{
    public function testMatchesANaiveDft(): void
    {
        $n = 64;
        $x = [];
        for ($i = 0; $i < $n; $i++) {
            $x[] = sin($i * 0.37) + 0.3 * cos($i * 1.9) + ($i % 5) * 0.01;
        }
        $re = $x;
        $im = array_fill(0, $n, 0.0);
        Fft::transform($re, $im);

        for ($k = 0; $k < $n; $k++) {
            $r = 0.0;
            $m = 0.0;
            foreach ($x as $i => $v) {
                $r += $v * cos(-2.0 * M_PI * $k * $i / $n);
                $m += $v * sin(-2.0 * M_PI * $k * $i / $n);
            }
            self::assertEqualsWithDelta($r, $re[$k], 1e-9, "re $k");
            self::assertEqualsWithDelta($m, $im[$k], 1e-9, "im $k");
        }
    }

    public function testRejectsANonPowerOfTwo(): void
    {
        $re = array_fill(0, 12, 0.0);
        $im = array_fill(0, 12, 0.0);

        $this->expectException(\InvalidArgumentException::class);
        Fft::transform($re, $im);
    }
}
