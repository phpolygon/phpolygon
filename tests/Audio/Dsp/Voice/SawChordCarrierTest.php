<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Voice\SawChordCarrier;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\TestCase;

final class SawChordCarrierTest extends TestCase
{
    public function testRendersExactlyTheRequestedLengthWithinFullScale(): void
    {
        $x = (new SawChordCarrier())->render(200.0, 22050, Pcm::SAMPLE_RATE, 4242);

        self::assertCount(22050, $x);
        self::assertLessThanOrEqual(1.0, max(array_map('abs', $x)));
        self::assertSame([], (new SawChordCarrier())->render(200.0, 0, Pcm::SAMPLE_RATE, 1));
    }

    public function testCarriesRootFifthAndOctave(): void
    {
        $sr = Pcm::SAMPLE_RATE;
        // Without detune each chord tone sits exactly on its frequency.
        $x = (new SawChordCarrier(detune: 0.0))->render(200.0, $sr, $sr, 4242);
        $from = 4410;
        $to = $sr - 4410;

        foreach ([200.0, 300.0, 400.0] as $hz) {
            self::assertGreaterThan(0.02, Signals::at($x, $hz, $from, $to), "$hz Hz");
        }
        // Between the chord tones there is (almost) nothing.
        self::assertLessThan(0.005, Signals::at($x, 250.0, $from, $to));
    }

    public function testIsBandLimited(): void
    {
        // A 2 kHz saw at 44.1 kHz: a naive saw folds its upper partials back
        // between the harmonics; PolyBLEP keeps that small.
        $sr = Pcm::SAMPLE_RATE;
        $x = (new SawChordCarrier(detune: 0.0))->render(2000.0, $sr, $sr, 1);
        $harmonic = Signals::at($x, 2000.0, 4410, $sr - 4410);
        $aliasBand = Signals::at($x, 2950.0, 4410, $sr - 4410);

        self::assertLessThan(0.02 * $harmonic, $aliasBand);
    }

    public function testFadesInAndOutAndIsDeterministicPerSeed(): void
    {
        $carrier = new SawChordCarrier();
        $a = $carrier->render(220.0, 8000, Pcm::SAMPLE_RATE, 9);

        self::assertSame(0.0, $a[0]);
        self::assertLessThan(0.05, abs($a[7999]));
        self::assertSame($a, $carrier->render(220.0, 8000, Pcm::SAMPLE_RATE, 9));
        self::assertNotSame($a, $carrier->render(220.0, 8000, Pcm::SAMPLE_RATE, 10));
    }

    public function testRejectsAWildDetune(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SawChordCarrier(detune: 0.5);
    }
}
