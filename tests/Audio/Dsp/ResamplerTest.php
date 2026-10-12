<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Resampler;
use PHPUnit\Framework\TestCase;

final class ResamplerTest extends TestCase
{
    public function testRatioOneIsTheIdentity(): void
    {
        $x = Signals::sine(440.0, 0.01);

        self::assertSame($x, Resampler::stretch($x, 1.0));
    }

    public function testStretchingByTwoHalvesThePitchAndDoublesTheLength(): void
    {
        $x = Signals::sine(800.0, 0.4);
        $y = Resampler::stretch($x, 2.0);

        self::assertCount(2 * count($x), $y);
        $mid = [(int) (0.2 * Pcm::SAMPLE_RATE), (int) (0.6 * Pcm::SAMPLE_RATE)];
        self::assertGreaterThan(0.24, Signals::at($y, 400.0, ...$mid));
        self::assertLessThan(0.002, Signals::at($y, 800.0, ...$mid));
    }

    public function testCompressingFiltersWhatWouldFoldOverNyquist(): void
    {
        // Played 1.25× faster, 19 kHz would land at 23.75 kHz – above Nyquist.
        $x = Signals::sine(19000.0, 0.3);
        $y = Resampler::stretch($x, 0.8);

        self::assertLessThan(0.005, Signals::rms($y, 1000, count($y) - 1000));
    }

    public function testRateConversionKeepsAPassbandToneAtFullLevel(): void
    {
        $x = Signals::sine(5000.0, 0.3, 0.5, 48000);
        $y = Resampler::convert($x, 48000, Pcm::SAMPLE_RATE);

        self::assertEqualsWithDelta(0.3 * Pcm::SAMPLE_RATE, count($y), 1.0);
        self::assertEqualsWithDelta(0.5 / sqrt(2.0), Signals::rms($y, 1000, count($y) - 1000), 0.004);
    }
}
