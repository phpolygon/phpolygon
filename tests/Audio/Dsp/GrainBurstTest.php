<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use InvalidArgumentException;
use PHPolygon\Audio\Dsp\BubbleGenerator;
use PHPolygon\Audio\Dsp\Fft;
use PHPolygon\Audio\Dsp\GrainBurst;
use PHPUnit\Framework\TestCase;

final class GrainBurstTest extends TestCase
{
    private const int SR = 48000;

    private static function grit(int $seed = 5): GrainBurst
    {
        return new GrainBurst(1800.0, 5000.0, 0.002, 0.006, 1.0, 0.1, 0.4, 30.0, $seed);
    }

    /** @param list<float> $x */
    private static function rms(array $x): float
    {
        $sum = 0.0;
        foreach ($x as $v) {
            $sum += $v * $v;
        }
        return sqrt($sum / max(1, count($x)));
    }

    public function testTheSameSeedGivesTheSameSamples(): void
    {
        $this->assertSame(self::grit()->render(0.3, 350.0, self::SR), self::grit()->render(0.3, 350.0, self::SR));
    }

    public function testRenderingTwiceFromOneObjectGivesTheSameSamples(): void
    {
        $burst = self::grit();

        $this->assertSame($burst->render(0.3, 350.0, self::SR), $burst->render(0.3, 350.0, self::SR));
    }

    public function testAnotherSeedIsAnotherTexture(): void
    {
        $this->assertNotSame(self::grit(5)->render(0.3, 350.0, self::SR), self::grit(6)->render(0.3, 350.0, self::SR));
    }

    public function testTheGrainsAreScheduledByTheBubbleGenerator(): void
    {
        $bubbles = new BubbleGenerator(1800.0, 5000.0, 0.002, 0.006, 0.0, 0.1, 0.4, 30.0, 5);

        $this->assertSame($bubbles->schedule(0.3, 350.0), self::grit()->schedule(0.3, 350.0));
    }

    public function testDensityMatchesOnAverage(): void
    {
        $grains = count((new GrainBurst(seed: 11))->schedule(10.0, 200.0));

        $this->assertEqualsWithDelta(2000, $grains, 150);
    }

    public function testNoDensityIsSilence(): void
    {
        $x = self::grit()->render(0.1, 0.0, self::SR);

        $this->assertCount(4800, $x);
        $this->assertSame(0.0, self::rms($x));
    }

    public function testStartsAndEndsAtZero(): void
    {
        $x = self::grit()->render(0.1, 2000.0, self::SR);

        $this->assertSame(0.0, $x[0]);
        $this->assertSame(0.0, $x[count($x) - 1]);
    }

    public function testAGrainReachesExactlyItsGain(): void
    {
        $burst = new GrainBurst(1000.0, 1000.0, 0.005, 0.005, 1.0, 0.3, 0.3, 30.0, 2);
        // A single grain somewhere in the window, far from its neighbours.
        $x = $burst->render(0.5, 4.0, self::SR);

        $this->assertNotEmpty($burst->schedule(0.5, 4.0));
        $this->assertEqualsWithDelta(0.3, max(array_map(abs(...), $x)), 1e-9);
    }

    public function testGrainsFollowTheDensityOverTime(): void
    {
        // Two lobes, as a foot rolling from heel to toe.
        $lobes = static fn (float $t): float => $t < 0.04 || ($t >= 0.06 && $t < 0.15) ? 600.0 : 0.0;

        foreach (self::grit()->schedule(0.24, $lobes) as $grain) {
            $this->assertFalse($grain['start'] >= 0.04 && $grain['start'] < 0.06, 'grain in the gap');
            $this->assertLessThan(0.15, $grain['start']);
        }

        $x = self::grit()->render(0.24, $lobes, self::SR);
        $this->assertGreaterThan(0.0, self::rms(array_slice($x, 0, 1920)));
        $this->assertSame(0.0, self::rms(array_slice($x, (int) (0.16 * self::SR))));
    }

    public function testTheEnergyStaysAroundTheBand(): void
    {
        $spectrum = self::powerSpectrum((new GrainBurst(1800.0, 5000.0, 0.004, 0.008, 2.0, 0.1, 0.4, 30.0, 3))->render(0.4, 800.0, self::SR));

        $this->assertGreaterThan(0.7, self::bandShare($spectrum, 1200.0, 7000.0));
        $this->assertLessThan(0.1, self::bandShare($spectrum, 12000.0, 24000.0));
    }

    /**
     * Why this class is not BubbleGenerator with glide 0: one sine grain is a narrow
     * ping, one noise grain covers the band. Spread = power-weighted deviation from
     * the spectral centroid, over the same schedule of 3 kHz grains.
     */
    public function testAGrainIsMuchWiderThanASineGrain(): void
    {
        $noise = [];
        $sine = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $seed) {
            $noise[] = self::spread((new GrainBurst(3000.0, 3000.0, 0.004, 0.004, 1.0, 0.3, 0.3, 30.0, $seed))->render(0.2, 12.0, self::SR));
            $sine[] = self::spread((new BubbleGenerator(3000.0, 3000.0, 0.004, 0.004, 0.0, 0.3, 0.3, 30.0, $seed))->render(0.2, 12.0, self::SR));
        }

        $this->assertGreaterThan(5.0 * max($sine), min($noise));
    }

    public function testInvalidParametersAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GrainBurst(q: 0.0);
    }

    public function testTheSharedParametersAreValidatedToo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GrainBurst(minHz: 500.0, maxHz: 100.0);
    }

    /**
     * Power per FFT bin up to Nyquist.
     *
     * @param list<float> $x
     * @return list<float>
     */
    private static function powerSpectrum(array $x): array
    {
        $size = 1 << (int) ceil(log(max(2, count($x)), 2));
        $re = array_pad($x, $size, 0.0);
        $im = array_fill(0, $size, 0.0);
        Fft::transform($re, $im);
        $power = [];
        for ($i = 0; $i <= $size / 2; $i++) {
            $power[] = $re[$i] * $re[$i] + $im[$i] * $im[$i];
        }
        return $power;
    }

    /** @param list<float> $power */
    private static function bandShare(array $power, float $fromHz, float $toHz): float
    {
        $hzPerBin = self::SR / 2 / (count($power) - 1);
        $inside = 0.0;
        $total = 0.0;
        foreach ($power as $bin => $p) {
            $hz = $bin * $hzPerBin;
            $total += $p;
            if ($hz >= $fromHz && $hz < $toHz) {
                $inside += $p;
            }
        }
        return $inside / max(1e-30, $total);
    }

    /** @param list<float> $pcm */
    private static function spread(array $pcm): float
    {
        $power = self::powerSpectrum($pcm);
        $hzPerBin = self::SR / 2 / (count($power) - 1);
        $total = array_sum($power);
        $centroid = 0.0;
        foreach ($power as $bin => $p) {
            $centroid += $p * $bin * $hzPerBin;
        }
        $centroid /= $total;
        $variance = 0.0;
        foreach ($power as $bin => $p) {
            $variance += $p * ($bin * $hzPerBin - $centroid) ** 2;
        }
        return sqrt($variance / $total);
    }
}
