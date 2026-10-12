<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use PHPolygon\Audio\Dsp\Loudness;
use PHPUnit\Framework\TestCase;

final class LoudnessTest extends TestCase
{
    public function testAFullScaleKiloHertzSineReadsMinusThreeLufsPerChannel(): void
    {
        // BS.1770 calibration: a 0 dBFS 1 kHz sine in one channel is −3.01 LUFS.
        $x = Signals::sine(1000.0, 2.0, 1.0);

        self::assertEqualsWithDelta(-3.01, Loudness::integratedMono($x), 0.1);
    }

    public function testMonoMatchesAStereoPairWithASilentSide(): void
    {
        $x = Signals::vowel(180.0, 1.0);

        self::assertEqualsWithDelta(
            Loudness::integrated($x, array_fill(0, count($x), 0.0)),
            Loudness::integratedMono($x),
            1e-9,
        );
    }

    public function testSilenceIsTheGateFloor(): void
    {
        self::assertSame(-70.0, Loudness::integratedMono(array_fill(0, 44100, 0.0)));
        self::assertSame(-70.0, Loudness::integratedMono([]));
    }
}
