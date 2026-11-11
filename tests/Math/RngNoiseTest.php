<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Math;

use PHPolygon\Math\Noise;
use PHPolygon\Math\Rng;
use PHPUnit\Framework\TestCase;

/**
 * Reference vectors were cross-checked against an independent Python port of
 * the documented algorithms (64-bit big-int arithmetic, no PHP code shared).
 */
final class RngNoiseTest extends TestCase
{
    public function testFmix32KnownValue(): void
    {
        // fmix32(1) = 0x514E28B7, cross-checked against the independent Python port.
        self::assertSame(0x514E28B7, Rng::fmix32(1));
    }

    public function testRngReferenceSequence(): void
    {
        $r = new Rng(12345);
        self::assertSame([4081083784, 3019263571, 896449920, 3609327391], [$r->nextU32(), $r->nextU32(), $r->nextU32(), $r->nextU32()]);
        self::assertSame(3239405183, (new Rng(-1))->nextU32());
    }

    public function testDeriveIsIndependentOfDraws(): void
    {
        $a = new Rng(99);
        $b = new Rng(99);
        for ($i = 0; $i < 10; $i++) {
            $b->nextU32();
        }
        self::assertSame($a->derive('facade')->seed(), $b->derive('facade')->seed());
        self::assertNotSame($a->derive('facade')->seed(), $a->derive('roof')->seed());
        self::assertSame($a->derive(7)->nextU32(), $a->derive('7')->nextU32());
        self::assertSame(-3282350593975428156, (new Rng(-1))->derive(7)->seed());
    }

    public function testRangesAndShuffle(): void
    {
        $r = new Rng(5);
        for ($i = 0; $i < 500; $i++) {
            $v = $r->int(-3, 4);
            self::assertGreaterThanOrEqual(-3, $v);
            self::assertLessThanOrEqual(4, $v);
            $f = $r->float();
            self::assertGreaterThanOrEqual(0.0, $f);
            self::assertLessThan(1.0, $f);
        }
        $s = (new Rng(5))->shuffle(range(1, 10));
        self::assertSame(range(1, 10), (function () use ($s) { sort($s); return $s; })());
        self::assertSame($s, (new Rng(5))->shuffle(range(1, 10)));
    }

    public function testNoiseReferenceVectors(): void
    {
        self::assertSame(1103511883, Noise::hash(-3, 5, 2, 99));
        self::assertEqualsWithDelta(-0.023470535340850928, Noise::value2(1.37, -2.9, 5), 1e-15);
        self::assertEqualsWithDelta(0.41379709293699274, Noise::gradient2(1.37, -2.9, 5), 1e-15);
        self::assertEqualsWithDelta(-0.0066718273631126, Noise::fbm2(0.3, 1.7, 4, 2.0, 0.5, 7), 1e-15);
    }

    public function testNoise3DStableAndBounded(): void
    {
        self::assertSame(Noise::value3(0.25, 0.5, 0.75, 1), Noise::value3(0.25, 0.5, 0.75, 1));
        $rng = new Rng(1);
        for ($i = 0; $i < 300; $i++) {
            $x = $rng->floatRange(-50, 50);
            $y = $rng->floatRange(-50, 50);
            $z = $rng->floatRange(-50, 50);
            foreach ([Noise::value3($x, $y, $z, 3), Noise::gradient3($x, $y, $z, 3), Noise::fbm3($x, $y, $z, 5, 2.0, 0.5, 3), Noise::gradient2($x, $y, 3), Noise::fbm2($x, $y, 5, 2.0, 0.5, 3, false)] as $n) {
                self::assertGreaterThanOrEqual(-1.0, $n);
                self::assertLessThanOrEqual(1.0, $n);
            }
        }
        // Gradient noise is zero on the lattice.
        self::assertSame(0.0, Noise::gradient2(3.0, 4.0, 1));
    }
}
