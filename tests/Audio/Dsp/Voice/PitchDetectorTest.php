<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Voice\PitchDetector;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PitchDetectorTest extends TestCase
{
    /** @return iterable<string, array{float}> */
    public static function tones(): iterable
    {
        foreach ([82.0, 110.0, 196.0, 261.6, 440.0, 698.0] as $hz) {
            yield "$hz Hz" => [$hz];
        }
    }

    #[DataProvider('tones')]
    public function testFindsTheFrequencyOfAPureToneWithinOnePercent(float $hz): void
    {
        $track = (new PitchDetector())->track(Signals::sine($hz, 0.5));

        self::assertGreaterThan(0.8, $track->voicedRatio());
        self::assertEqualsWithDelta($hz, $track->medianF0(), $hz * 0.01);
    }

    /** @return iterable<string, array{float}> */
    public static function vowels(): iterable
    {
        foreach ([95.0, 130.0, 220.0, 330.0] as $hz) {
            yield "vowel at $hz Hz" => [$hz];
        }
    }

    #[DataProvider('vowels')]
    public function testFindsTheFundamentalOfAVowelNotAFormantHarmonic(float $hz): void
    {
        $track = (new PitchDetector())->track(Signals::vowel($hz, 0.5));

        self::assertGreaterThan(0.8, $track->voicedRatio());
        self::assertEqualsWithDelta($hz, $track->medianF0(), $hz * 0.01);
    }

    public function testFollowsAGlide(): void
    {
        $x = Signals::vowel(150.0, 1.0, glide: 1.4);
        $track = (new PitchDetector())->track($x);
        $sr = Pcm::SAMPLE_RATE;

        self::assertEqualsWithDelta(150.0 * 1.1, $track->f0At((int) (0.25 * $sr)), 150.0 * 1.1 * 0.02);
        self::assertEqualsWithDelta(150.0 * 1.3, $track->f0At((int) (0.75 * $sr)), 150.0 * 1.3 * 0.02);
    }

    public function testSilenceAndNoiseAreUnvoiced(): void
    {
        $detector = new PitchDetector();

        self::assertSame(0.0, $detector->track(array_fill(0, 22050, 0.0))->voicedRatio());
        self::assertLessThan(0.1, $detector->track(Signals::noise(0.5))->voicedRatio());
    }

    public function testMarksTheSilentTailUnvoiced(): void
    {
        $x = array_merge(Signals::vowel(200.0, 0.3), array_fill(0, (int) (0.3 * Pcm::SAMPLE_RATE), 0.0));
        $track = (new PitchDetector())->track($x);

        self::assertTrue($track->isVoiced((int) (0.15 * Pcm::SAMPLE_RATE)));
        self::assertFalse($track->isVoiced((int) (0.45 * Pcm::SAMPLE_RATE)));
        self::assertSame(0.0, $track->f0At((int) (0.45 * Pcm::SAMPLE_RATE)));
    }

    public function testTheHopIsAboutTenMillisecondsAndFollowsTheRate(): void
    {
        self::assertSame(440, (new PitchDetector())->hop);
        self::assertSame(480, (new PitchDetector(sampleRate: 48000))->hop);
    }

    public function testFindsTheFrequencyAtAnotherSampleRate(): void
    {
        $track = (new PitchDetector(sampleRate: 48000))->track(Signals::vowel(180.0, 0.5, sampleRate: 48000));

        self::assertEqualsWithDelta(180.0, $track->medianF0(), 180.0 * 0.01);
    }

    public function testTheTrackCoversTheWholeSignalAndIsDeterministic(): void
    {
        $x = Signals::vowel(180.0, 0.4);
        $a = (new PitchDetector())->track($x);

        self::assertSame(count($x), $a->length);
        self::assertGreaterThanOrEqual(count($x), count($a->f0) * $a->hop);
        self::assertSame($a->f0, (new PitchDetector())->track($x)->f0);
    }

}
