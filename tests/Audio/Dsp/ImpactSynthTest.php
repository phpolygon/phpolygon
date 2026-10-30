<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use InvalidArgumentException;
use PHPolygon\Audio\Dsp\ImpactNoise;
use PHPolygon\Audio\Dsp\ImpactSynth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImpactSynthTest extends TestCase
{
    private const int SR = 48000;

    private static function recipe(int $seed = 7, float $detune = 0.06): ImpactSynth
    {
        return new ImpactSynth(
            thumpHz: 95.0,
            thumpGain: 0.6,
            thumpDecaySeconds: 0.035,
            modes: [[640.0, 0.12, 0.015], [1150.0, 0.08, 0.01]],
            noise: [
                new ImpactNoise(0.3, 1.0 / 90.0, bandHz: 2800.0),
                new ImpactNoise(0.05, 0.01, delaySeconds: 0.07, highPassHz: 1500.0),
            ],
            strikes: [[0.0, 1.0], [0.05, 0.3]],
            detune: $detune,
            seed: $seed,
        );
    }

    /** Magnitude of $x at $hz, independent of the length (a sine of amplitude a gives about a / 2 × its window). @param list<float> $x */
    private static function magnitude(array $x, float $hz): float
    {
        $re = 0.0;
        $im = 0.0;
        foreach ($x as $i => $v) {
            $w = 2.0 * M_PI * $hz * $i / self::SR;
            $re += $v * cos($w);
            $im += $v * sin($w);
        }
        return sqrt($re * $re + $im * $im) / max(1, count($x));
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
        $this->assertSame(self::recipe()->render(0.2, self::SR), self::recipe()->render(0.2, self::SR));
    }

    public function testRenderingTwiceFromOneObjectGivesTheSameSamples(): void
    {
        $synth = self::recipe();

        $this->assertSame($synth->render(0.2, self::SR), $synth->render(0.2, self::SR));
    }

    public function testAnotherSeedIsAnotherVariant(): void
    {
        $this->assertNotSame(self::recipe(7)->render(0.2, self::SR), self::recipe(8)->render(0.2, self::SR));
    }

    public function testDetuneDoesNotChangeTheNoise(): void
    {
        $noiseOnly = static fn (float $detune): array => (new ImpactSynth(
            thumpGain: 0.0,
            noise: [new ImpactNoise(0.5, 0.02)],
            detune: $detune,
            seed: 3,
        ))->render(0.1, self::SR);

        $this->assertSame($noiseOnly(0.0), $noiseOnly(0.1));
    }

    public function testDetuneMovesThePartialsWithinItsRange(): void
    {
        $hz = 400.0;
        $peakAt = static function (int $seed) use ($hz): float {
            $x = (new ImpactSynth(thumpHz: $hz, thumpDecaySeconds: 0.2, detune: 0.05, attackSeconds: 0.0, seed: $seed))
                ->render(0.4, self::SR);
            $best = 0.0;
            $bestHz = 0.0;
            for ($f = $hz * 0.9; $f <= $hz * 1.1; $f += 1.0) {
                $m = self::magnitude($x, $f);
                if ($m > $best) {
                    $best = $m;
                    $bestHz = $f;
                }
            }
            return $bestHz;
        };

        $seen = [];
        foreach ([1, 2, 3, 4, 5, 6] as $seed) {
            $f = $peakAt($seed);
            $seen[] = $f;
            $this->assertGreaterThanOrEqual($hz * 0.945, $f);
            $this->assertLessThanOrEqual($hz * 1.055, $f);
        }
        $this->assertGreaterThan(1, count(array_unique($seen)), 'seeds must actually detune');
    }

    public function testExactLengthAtEverySampleRate(): void
    {
        $this->assertCount(9600, self::recipe()->render(0.2, 48000));
        $this->assertCount(8820, self::recipe()->render(0.2, 44100));
        $this->assertSame([], self::recipe()->render(0.0, self::SR));
    }

    public function testStartsAndEndsAtZero(): void
    {
        $x = self::recipe()->render(0.2, self::SR);

        $this->assertSame(0.0, $x[0]);
        $this->assertSame(0.0, $x[count($x) - 1]);
    }

    public function testNoClickAtTheEndOfARingingSound(): void
    {
        // Cut mid-ring: the closing fade must take the level down in small steps.
        $x = (new ImpactSynth(thumpHz: 200.0, thumpDecaySeconds: 10.0))->render(0.05, self::SR);
        $n = count($x);

        for ($i = $n - 96; $i < $n - 1; $i++) {
            $this->assertLessThan(0.1, abs($x[$i + 1] - $x[$i]), "jump at sample {$i}");
        }
    }

    public function testTheThumpRingsAtItsFrequencyAndDecays(): void
    {
        $x = (new ImpactSynth(thumpHz: 100.0, thumpGain: 1.0, thumpDecaySeconds: 0.05))->render(0.3, self::SR);

        $this->assertGreaterThan(self::magnitude($x, 300.0) * 5.0, self::magnitude($x, 100.0));

        // Over one time constant the envelope falls by e; whole periods at 100 Hz.
        $early = self::rms(array_slice($x, 960, 960));
        $late = self::rms(array_slice($x, 960 + 2400, 960));
        $this->assertEqualsWithDelta(exp(-1.0), $late / $early, 0.02);
    }

    public function testEveryModeRingsAtItsOwnFrequencyAndGain(): void
    {
        $x = (new ImpactSynth(
            thumpGain: 0.0,
            modes: [[1000.0, 1.0, 0.05], [3000.0, 0.25, 0.05]],
        ))->render(0.3, self::SR);

        $ratio = self::magnitude($x, 3000.0) / self::magnitude($x, 1000.0);

        $this->assertEqualsWithDelta(0.25, $ratio, 0.02);
        $this->assertLessThan(self::magnitude($x, 1000.0) * 0.02, self::magnitude($x, 2000.0));
    }

    public function testASecondStrikeRepeatsTheBody(): void
    {
        $one = (new ImpactSynth(thumpHz: 200.0, thumpDecaySeconds: 0.01))->render(0.2, self::SR);
        $two = (new ImpactSynth(thumpHz: 200.0, thumpDecaySeconds: 0.01, strikes: [[0.0, 1.0], [0.1, 0.5]]))->render(0.2, self::SR);

        // 96 samples into the second strike (2 ms: past its attack).
        $window = static fn (array $x): array => array_slice($x, 4800 + 96, 480);
        $this->assertLessThan(0.01, self::rms($window($one)));
        $this->assertGreaterThan(0.1, self::rms($window($two)));
        $this->assertSame(array_slice($one, 0, 4800), array_slice($two, 0, 4800));
    }

    public function testANoiseBurstWaitsForItsDelayAndKeepsItsBand(): void
    {
        $x = (new ImpactSynth(
            thumpGain: 0.0,
            noise: [new ImpactNoise(1.0, 0.03, delaySeconds: 0.05, bandHz: 4000.0, bandQ: 4.0)],
        ))->render(0.2, self::SR);

        $this->assertSame(0.0, self::rms(array_slice($x, 0, 2400)));
        $this->assertGreaterThan(0.0, self::rms(array_slice($x, 2400, 2400)));
        $this->assertGreaterThan(self::magnitude($x, 500.0) * 2.0, self::magnitude($x, 4000.0));
    }

    public function testLowAndHighPassShapeTheNoise(): void
    {
        $share = static function (ImpactNoise $noise): float {
            $x = (new ImpactSynth(thumpGain: 0.0, noise: [$noise]))->render(0.2, self::SR);
            $low = 0.0;
            $high = 0.0;
            for ($f = 200.0; $f < 1000.0; $f += 37.0) {
                $low += self::magnitude($x, $f);
            }
            for ($f = 8000.0; $f < 12000.0; $f += 211.0) {
                $high += self::magnitude($x, $f);
            }
            return $high / $low;
        };

        $this->assertLessThan(0.25, $share(new ImpactNoise(1.0, 0.05, lowPassHz: 1500.0)));
        $this->assertGreaterThan(1.0, $share(new ImpactNoise(1.0, 0.05, highPassHz: 4000.0)));
    }

    /** The rate belongs to render(), so a recipe must survive a rate it was not written for. */
    public function testFiltersAboveNyquistAreClampedAtRenderTime(): void
    {
        $synth = new ImpactSynth(
            thumpGain: 0.0,
            noise: [
                new ImpactNoise(0.5, 0.01, bandHz: 15000.0),
                new ImpactNoise(0.5, 0.01, lowPassHz: 15000.0),
                new ImpactNoise(0.5, 0.01, highPassHz: 15000.0),
            ],
        );

        $x = $synth->render(0.05, 22050);

        $this->assertCount(1103, $x);
        foreach ($x as $v) {
            $this->assertTrue(is_finite($v));
        }
        $this->assertGreaterThan(0.0, self::rms($x));
    }

    public function testAZeroAttackStillStartsAtZero(): void
    {
        $x = (new ImpactSynth(
            thumpGain: 0.0,
            noise: [new ImpactNoise(1.0, 0.01, attackSeconds: 0.0)],
            attackSeconds: 0.0,
        ))->render(0.1, self::SR);

        $this->assertSame(0.0, $x[0]);
        $this->assertGreaterThan(0.0, self::rms($x));
    }

    /** @return iterable<string, array{array<string, float>}> */
    public static function impossibleFilters(): iterable
    {
        yield 'band at 0' => [['bandHz' => 0.0]];
        yield 'negative band' => [['bandHz' => -100.0]];
        yield 'band Q 0' => [['bandQ' => 0.0]];
        yield 'low-pass at 0' => [['lowPassHz' => 0.0]];
        yield 'high-pass at 0' => [['highPassHz' => 0.0]];
    }

    /** @param array<string, float> $filter */
    #[DataProvider('impossibleFilters')]
    public function testImpossibleFiltersAreRefusedAtConstruction(array $filter): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImpactNoise(1.0, 0.01, ...$filter);
    }

    public function testInvalidParametersAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImpactSynth(detune: 1.0);
    }

    public function testNegativeStrikeTimeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImpactSynth(strikes: [[-0.1, 1.0]]);
    }

    public function testAZeroDecayOfANoiseBurstIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImpactNoise(1.0, 0.0);
    }
}
