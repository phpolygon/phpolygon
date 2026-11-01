<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Biquad;
use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Voice\TailBreathTrimmer;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\TestCase;

final class TailBreathTrimmerTest extends TestCase
{
    /** @var list<float>|null */
    private static ?array $word = null;

    /** @return list<float> a voiced word at a typical recording level, its end decaying over 30 ms */
    private static function word(): array
    {
        if (self::$word !== null) {
            return self::$word;
        }
        $y = Signals::vowel(180.0, 0.9, amp: 0.25);
        $n = count($y);
        $fade = (int) (0.03 * Pcm::SAMPLE_RATE);
        for ($i = 0; $i < $fade; $i++) {
            $y[$n - 1 - $i] *= $i / $fade;
        }
        return self::$word = $y;
    }

    /** @return list<float> */
    private static function silence(float $seconds): array
    {
        return array_fill(0, (int) round($seconds * Pcm::SAMPLE_RATE), 0.0);
    }

    /**
     * Noise shaped by a band and an amplitude ramp, $attack and $release in seconds.
     *
     * @return list<float>
     */
    private static function shaped(float $seconds, float $amp, float $attack, float $release, float $loHz, float $hiHz, int $seed = 3): array
    {
        $y = Signals::noise($seconds, 1.0, $seed);
        $y = Biquad::highPass($loHz)->process(Biquad::highPass($loHz)->process($y));
        $y = Biquad::lowPass($hiHz)->process(Biquad::lowPass($hiHz)->process($y));
        $rms = max(1e-9, Signals::rms($y));
        $n = count($y);
        foreach ($y as $i => $v) {
            $t = $i / Pcm::SAMPLE_RATE;
            $gain = min(1.0, $t / max(1e-4, $attack), ($seconds - $t) / max(1e-4, $release));
            $y[$i] = $v * $gain * $amp / $rms;
        }
        return $y;
    }

    /** @return list<float> an inhalation: broad, fades in over 120 ms, about 26 dB under the word */
    private static function breath(float $seconds = 0.45): array
    {
        return self::shaped($seconds, 0.008, 0.12, 0.1, 500.0, 7000.0);
    }

    /** @return list<float> */
    private static function join(array ...$parts): array
    {
        return array_merge(...$parts);
    }

    public function testBreathBehindAGapIsRemoved(): void
    {
        $word = self::word();
        $x = self::join($word, self::silence(0.15), self::breath());

        $y = (new TailBreathTrimmer())->trim($x);

        self::assertLessThan(count($word) + 0.15 * Pcm::SAMPLE_RATE, count($y));
        self::assertGreaterThanOrEqual(count($word), count($y));
    }

    public function testNothingOfTheKeptPartIsTouchedButTheFade(): void
    {
        $word = self::word();
        $x = self::join($word, self::silence(0.2), self::breath());

        $y = (new TailBreathTrimmer())->trim($x);

        $fade = (int) round(Pcm::SAMPLE_RATE * TailBreathTrimmer::FADE_MS / 1000.0);
        self::assertSame(array_slice($x, 0, count($y) - $fade), array_slice($y, 0, count($y) - $fade));
    }

    public function testTheCutEndsInSilenceWithoutAClick(): void
    {
        $x = self::join(self::word(), self::shaped(0.2, 0.002, 0.0, 0.2, 800.0, 5000.0), self::silence(0.1), self::breath());

        $y = (new TailBreathTrimmer())->trim($x);

        self::assertLessThan(count($x), count($y));
        self::assertLessThan(1e-3, abs($y[count($y) - 1]));
    }

    public function testASignalWithoutATailStaysSampleIdentical(): void
    {
        $x = self::word();

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testATailOfSilenceStaysSampleIdentical(): void
    {
        $x = self::join(self::word(), self::silence(0.6));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testAFinalFricativeAttachedToTheVowelStays(): void
    {
        $x = self::join(self::word(), self::shaped(0.28, 0.05, 0.02, 0.06, 4000.0, 10000.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testAQuietFricativeAttachedToTheVowelStays(): void
    {
        // f / h: weak and broad, no gap in front of it.
        $x = self::join(self::word(), self::shaped(0.3, 0.008, 0.03, 0.08, 800.0, 8000.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testAReleaseBehindAClosureStays(): void
    {
        $x = self::join(self::word(), self::silence(0.07), self::shaped(0.06, 0.06, 0.002, 0.04, 1500.0, 8000.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testALongAspiratedReleaseStays(): void
    {
        $x = self::join(self::word(), self::silence(0.09), self::shaped(0.14, 0.03, 0.003, 0.11, 1200.0, 7000.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testAnAffricateBehindAClosureStays(): void
    {
        // "ts": closure, then a burst that goes straight into a hiss of 220 ms.
        $x = self::join(self::word(), self::silence(0.06), self::shaped(0.22, 0.05, 0.004, 0.09, 3500.0, 10000.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testBreathBehindAReleaseGoesAndTheReleaseStays(): void
    {
        $word = self::word();
        $release = self::shaped(0.06, 0.06, 0.002, 0.04, 1500.0, 8000.0);
        $x = self::join($word, self::silence(0.07), $release, self::silence(0.16), self::breath());

        $y = (new TailBreathTrimmer())->trim($x);

        $releaseEnd = count($word) + (int) (0.07 * Pcm::SAMPLE_RATE) + count($release);
        self::assertLessThan(count($x) - 0.3 * Pcm::SAMPLE_RATE, count($y));
        self::assertGreaterThanOrEqual($releaseEnd, count($y));
    }

    public function testALoudFinalHissBehindAShortClosureStays(): void
    {
        // The s of "books": 40 ms closure, 250 ms, rise of 50 ms, 10 dB under the speech.
        $x = self::join(self::word(), self::silence(0.04), self::shaped(0.25, 0.03, 0.05, 0.1, 3500.0, 10000.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testADevoicedDarkSyllableBehindAPauseStays(): void
    {
        // No pitch to find, but dark and only 12 dB under the speech: a word end, not a breath.
        $x = self::join(self::word(), self::silence(0.12), self::shaped(0.3, 0.02, 0.05, 0.1, 150.0, 900.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testAMumbledEndOfAQuietSentenceStays(): void
    {
        // The sentence fades 16 dB down; what follows is near that level, however far under the speech.
        $fading = array_map(static fn (float $v): float => $v * 0.15, Signals::vowel(170.0, 0.4, amp: 0.25));
        $x = self::join(self::word(), self::silence(0.1), $fading, self::silence(0.06), self::shaped(0.3, 0.005, 0.06, 0.1, 900.0, 3500.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testASyllableBehindAPauseStays(): void
    {
        $x = self::join(self::word(), self::silence(0.2), Signals::vowel(170.0, 0.3, amp: 0.2));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testBreathLoudAsSpeechIsNotTouched(): void
    {
        $x = self::join(self::word(), self::silence(0.15), self::shaped(0.45, 0.09, 0.12, 0.1, 500.0, 7000.0));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testSilenceStaysAsItIs(): void
    {
        $x = self::silence(1.0);

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testEmptyAndVeryShortClipsStayAsTheyAre(): void
    {
        $trimmer = new TailBreathTrimmer();

        self::assertSame([], $trimmer->trim([]));
        self::assertSame([0.1], $trimmer->trim([0.1]));
        $short = array_slice(self::word(), 0, 3000);
        self::assertSame($short, $trimmer->trim($short));
    }

    public function testNoiseWithoutAnyVoiceIsLeftAlone(): void
    {
        $x = self::join(self::silence(0.2), self::breath(0.8), self::silence(0.2), self::breath(0.5));

        self::assertSame($x, (new TailBreathTrimmer())->trim($x));
    }

    public function testTheLevelOfTheRecordingDoesNotMatter(): void
    {
        $x = self::join(self::word(), self::silence(0.15), self::breath());
        $quiet = array_map(static fn (float $v): float => $v * 0.05, $x);

        $trimmer = new TailBreathTrimmer();

        self::assertSame($trimmer->analyse($x)->cut, $trimmer->analyse($quiet)->cut);
        self::assertNotNull($trimmer->analyse($x)->cut);
    }

    public function testTheSampleRateDoesNotMatter(): void
    {
        $sr = 22050;
        $x = self::join(self::word(), self::silence(0.15), self::breath());
        $half = [];
        foreach ($x as $i => $v) {
            if ($i % 2 === 0) {
                $half[] = $v;
            }
        }

        $full = (new TailBreathTrimmer())->analyse($x)->cut;
        $low = (new TailBreathTrimmer($sr))->analyse($half)->cut;

        self::assertNotNull($full);
        self::assertNotNull($low);
        self::assertEqualsWithDelta($full / 44100, $low / $sr, 0.02);
    }

    public function testCutAtCutsWhereItIsToldWithTheFadeOfTrim(): void
    {
        $x = self::join(self::word(), self::silence(0.15), self::breath());
        $trimmer = new TailBreathTrimmer();
        $cut = $trimmer->analyse($x)->cut;
        self::assertNotNull($cut);

        self::assertSame($trimmer->trim($x), $trimmer->cutAt($x, $cut / Pcm::SAMPLE_RATE));
    }

    public function testCutAtCutsWhatTheDetectionLeavesAlone(): void
    {
        // A sound the measures may not touch: the caller knows better.
        $word = self::word();
        $x = self::join($word, self::silence(0.1), self::shaped(0.3, 0.08, 0.05, 0.1, 150.0, 900.0));
        $trimmer = new TailBreathTrimmer();
        self::assertSame($x, $trimmer->trim($x));

        $y = $trimmer->cutAt($x, (count($word) + 2000) / Pcm::SAMPLE_RATE);

        self::assertCount(count($word) + 2000, $y);
        self::assertSame(array_slice($x, 0, count($word)), array_slice($y, 0, count($word)));
        self::assertLessThan(1e-3, abs($y[count($y) - 1]));
    }

    public function testCutAtBehindTheEndChangesNothing(): void
    {
        $x = self::word();
        $seconds = count($x) / Pcm::SAMPLE_RATE;

        self::assertSame($x, (new TailBreathTrimmer())->cutAt($x, $seconds));
        self::assertSame($x, (new TailBreathTrimmer())->cutAt($x, $seconds + 1.0));
    }

    public function testCutAtFollowsTheSampleRate(): void
    {
        $x = Signals::noise(1.0, 0.1);

        self::assertCount(11025, (new TailBreathTrimmer(22050))->cutAt($x, 0.5));
    }

    public function testCutAtRefusesAPositionThatIsNone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TailBreathTrimmer())->cutAt(self::word(), -0.1);
    }

    public function testIsDeterministic(): void
    {
        $x = self::join(self::word(), self::silence(0.15), self::breath());

        self::assertSame((new TailBreathTrimmer())->trim($x), (new TailBreathTrimmer())->trim($x));
    }

    public function testAnalysisNamesTheVoiceEndAndTheSounds(): void
    {
        $word = self::word();
        $a = (new TailBreathTrimmer())->analyse(self::join($word, self::silence(0.15), self::breath()));

        self::assertNotNull($a->voiceEnd);
        self::assertEqualsWithDelta(count($word), $a->voiceEnd, 0.03 * Pcm::SAMPLE_RATE);
        self::assertNotSame([], $a->events);
        self::assertLessThan(-15.0, $a->events[count($a->events) - 1]->peakDb);
    }
}
