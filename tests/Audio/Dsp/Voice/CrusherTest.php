<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Voice\Crusher;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\TestCase;

final class CrusherTest extends TestCase
{
    /** @param list<float> $x share of 10 ms windows that are silent */
    private static function droppedShare(array $x): float
    {
        $silent = 0;
        $windows = 0;
        for ($s = 0; $s + 441 <= count($x); $s += 441) {
            $windows++;
            if (Signals::rms($x, $s, $s + 441) < 1e-6) {
                $silent++;
            }
        }
        return $silent / max(1, $windows);
    }

    public function testZeroIsTheIdentity(): void
    {
        $x = Signals::sine(300.0, 0.1);

        self::assertSame($x, (new Crusher())->apply($x, 0.0));
    }

    public function testHeavyCrushReducesBitsAndRate(): void
    {
        $x = Signals::sine(300.0, 0.5, 0.8);
        $y = (new Crusher())->apply($x, 0.7);

        self::assertCount(count($x), $y);
        $levels = count(array_unique(array_map(static fn (float $v): string => sprintf('%.9f', $v), $y)));
        self::assertLessThan(260, $levels, 'about 8 bits left');
        // Sample-and-hold: the waveform moves in steps of several samples.
        $changes = 0;
        for ($i = 1; $i < count($y); $i++) {
            if ($y[$i] !== $y[$i - 1]) {
                $changes++;
            }
        }
        self::assertLessThan(count($y) / 4, $changes);
    }

    public function testDropoutsGrowWithTheAmountAndSilenceStaysSilent(): void
    {
        $x = Signals::sine(220.0, 4.0, 0.5);
        $heavy = self::droppedShare((new Crusher())->apply($x, 0.7));
        $light = self::droppedShare((new Crusher())->apply($x, 0.05));

        self::assertGreaterThan(0.05, $heavy);
        self::assertLessThan(0.4, $heavy, 'still intelligible');
        self::assertLessThan($heavy / 3, $light);
        self::assertSame(0.0, max(array_map('abs', (new Crusher())->apply(array_fill(0, 4410, 0.0), 0.7))));
    }

    public function testDropoutsFadeInsteadOfClicking(): void
    {
        $x = Signals::sine(220.0, 2.0, 0.5);
        $y = (new Crusher(seed: 3))->apply($x, 0.3);
        $maxStep = 0.0;
        for ($i = 1; $i < count($y); $i++) {
            $maxStep = max($maxStep, abs($y[$i] - $y[$i - 1]));
        }
        // A hard cut of a 0.5 sine would step by up to 0.5 – fades keep it near the waveform's own steps.
        self::assertLessThan(0.15, $maxStep);
    }

    public function testIsDeterministicPerSeed(): void
    {
        $x = Signals::sine(250.0, 1.0);

        self::assertSame((new Crusher(seed: 9))->apply($x, 0.5), (new Crusher(seed: 9))->apply($x, 0.5));
        self::assertNotSame((new Crusher(seed: 9))->apply($x, 0.5), (new Crusher(seed: 10))->apply($x, 0.5));
    }
}
