<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Pcm;
use PHPolygon\Audio\Dsp\Voice\PitchDetector;
use PHPolygon\Audio\Dsp\Voice\VoiceProfile;
use PHPolygon\Audio\Dsp\Voice\VoiceReference;
use PHPolygon\Audio\Dsp\Voice\VoiceShaper;
use PHPolygon\Tests\Audio\Dsp\Signals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VoiceShaperTest extends TestCase
{
    /** @var array<string, list<float>> */
    private static array $vowels = [];

    /** @return list<float> cached synthetic vowel */
    private static function vowel(float $f0, float $seconds = 0.8): array
    {
        return self::$vowels["$f0/$seconds"] ??= Signals::vowel($f0, $seconds);
    }

    /** @param list<float> $x */
    private static function f0(array $x): float
    {
        return (new PitchDetector())->track($x)->medianF0();
    }

    /** @return iterable<string, array{float, float}> */
    public static function shifts(): iterable
    {
        foreach ([120.0, 210.0] as $f0) {
            foreach ([0.85, 0.92, 1.08, 1.15] as $pitch) {
                yield "$f0 Hz × $pitch" => [$f0, $pitch];
            }
        }
    }

    public function testANeutralProfileReturnsTheRecordingUntouched(): void
    {
        $x = self::vowel(200.0, 0.3);

        self::assertSame($x, (new VoiceShaper())->shape($x, new VoiceProfile()));
    }

    #[DataProvider('shifts')]
    public function testPitchMovesByTheFactorWithinTwoPercent(float $f0, float $pitch): void
    {
        $y = (new VoiceShaper())->shape(self::vowel($f0), new VoiceProfile(pitch: $pitch));

        self::assertCount(count(self::vowel($f0)), $y, 'tempo 1 keeps the length');
        self::assertEqualsWithDelta($f0 * $pitch, self::f0($y), $f0 * $pitch * 0.02);
    }

    #[DataProvider('shifts')]
    public function testAPitchShiftKeepsTheFormants(float $f0, float $pitch): void
    {
        $x = self::vowel($f0);
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(pitch: $pitch));

        // LPC formants stay where they were: the vocal-tract scale within 6 %,
        // each formant within 10 % (LPC itself wavers that much on a 210 Hz voice) …
        $rx = VoiceReference::analyse($x);
        $ry = VoiceReference::analyse($y);
        self::assertEqualsWithDelta(1.0, $rx->formantRatioTo($ry), 0.06);
        foreach ([0, 1, 2] as $k) {
            $a = $rx->formants[$k];
            $b = $ry->formants[$k];
            self::assertEqualsWithDelta($a, $b, $a * 0.10, sprintf('F%d %.0f → %.0f Hz', $k + 1, $a, $b));
        }
        // … and the centroid moves far less than a plain resample would (the full factor).
        $cx = Signals::centroid($x, from: 4410, to: count($x) - 4410);
        $cy = Signals::centroid($y, from: 4410, to: count($y) - 4410);
        self::assertLessThan(0.5 * abs($pitch - 1.0), abs($cy / $cx - 1.0), sprintf('centroid %.0f → %.0f Hz', $cx, $cy));
    }

    #[DataProvider('shifts')]
    public function testAPitchShiftStaysHarmonicWithoutClicksOrFlutter(float $f0, float $pitch): void
    {
        $x = self::vowel($f0);
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(pitch: $pitch));
        $from = 4410;
        $to = count($y) - 4410;

        // Metallic / rough PSOLA leaves energy between the harmonics.
        $target = $f0 * $pitch;
        $spec = Signals::spectrum($y, $from, $to);
        $binHz = Pcm::SAMPLE_RATE / 4096;
        $on = 0.0;
        $all = 0.0;
        foreach ($spec as $k => $m) {
            $f = $k * $binHz;
            if ($f < 60.0 || $f > 6000.0) {
                continue;
            }
            $p = $m * $m;
            $all += $p;
            $h = $f / $target;
            if (abs($h - round($h)) * $target < 3.0 * $binHz) {
                $on += $p;
            }
        }
        self::assertGreaterThan(0.97, $on / $all, 'energy must sit on the new harmonics');

        // Flutter: the level over every 3 new periods of a steady vowel must stay steady.
        $win = (int) round(3 * Pcm::SAMPLE_RATE / $target);
        $levels = [];
        for ($s = $from; $s + $win <= $to; $s += intdiv($win, 3)) {
            $levels[] = 20.0 * log10(Signals::rms($y, $s, $s + $win));
        }
        self::assertLessThan(1.0, max($levels) - min($levels), 'level flutter in dB');

        // Clicks: no sample step much larger than the source's largest.
        $step = static function (array $v, int $a, int $b): float {
            $m = 0.0;
            for ($i = $a + 1; $i < $b; $i++) {
                $m = max($m, abs($v[$i] - $v[$i - 1]));
            }
            return $m;
        };
        $scale = max(array_map('abs', $y)) / max(array_map('abs', $x));
        self::assertLessThan(1.6 * $step($x, 0, count($x)) * $scale, $step($y, 0, count($y)));
    }

    public function testFollowsAGlideWhileShifting(): void
    {
        $x = Signals::vowel(150.0, 1.0, glide: 1.4);
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(pitch: 1.12));
        $track = (new PitchDetector())->track($y);

        self::assertEqualsWithDelta(165.0 * 1.12, $track->f0At((int) (0.25 * Pcm::SAMPLE_RATE)), 165.0 * 1.12 * 0.02);
        self::assertEqualsWithDelta(195.0 * 1.12, $track->f0At((int) (0.75 * Pcm::SAMPLE_RATE)), 195.0 * 1.12 * 0.02);
    }

    public function testARangeScaleNarrowsTheMelodyAroundThePivot(): void
    {
        $x = Signals::vowel(150.0, 1.0, glide: 1.4);
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(), rangeScale: 0.5, pivotHz: 180.0);
        $track = (new PitchDetector())->track($y);

        // f_out = pivot · (f_in / pivot)^0.5
        foreach ([[0.25, 165.0], [0.75, 195.0]] as [$t, $fin]) {
            $expect = 180.0 * sqrt($fin / 180.0);
            self::assertEqualsWithDelta($expect, $track->f0At((int) ($t * Pcm::SAMPLE_RATE)), $expect * 0.02, "at {$t} s");
        }
    }

    /** @return iterable<string, array{float}> */
    public static function formants(): iterable
    {
        yield 'smaller head' => [1.08];
        yield 'larger head' => [0.92];
    }

    #[DataProvider('formants')]
    public function testAFormantShiftMovesTheFormantsButNotThePitch(float $formant): void
    {
        $x = self::vowel(150.0);
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(formant: $formant));

        self::assertCount(count($x), $y);
        self::assertEqualsWithDelta(150.0, self::f0($y), 150.0 * 0.02);
        $cx = Signals::centroid($x, from: 4410, to: count($x) - 4410);
        $cy = Signals::centroid($y, from: 4410, to: count($y) - 4410);
        self::assertEqualsWithDelta($formant, $cy / $cx, 0.03, sprintf('centroid %.0f → %.0f Hz', $cx, $cy));
    }

    public function testTempoOneKeepsTheTimingOfEveryEvent(): void
    {
        $sr = Pcm::SAMPLE_RATE;
        $x = array_merge(array_fill(0, (int) (0.4 * $sr), 0.0), self::vowel(180.0, 0.3), array_fill(0, (int) (0.4 * $sr), 0.0), self::vowel(180.0, 0.3));
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(pitch: 1.1, formant: 1.05));

        self::assertCount(count($x), $y);
        foreach ([0.4, 1.1] as $onset) {
            self::assertEqualsWithDelta($onset * $sr, self::onset($y, (int) (($onset - 0.2) * $sr)), 0.01 * count($x));
        }
    }

    /** @return iterable<string, array{float}> */
    public static function tempos(): iterable
    {
        yield 'faster' => [1.06];
        yield 'slower' => [0.94];
        yield 'much faster' => [1.3];
    }

    #[DataProvider('tempos')]
    public function testTempoChangesTheDurationButNotThePitch(float $tempo): void
    {
        $sr = Pcm::SAMPLE_RATE;
        $x = array_merge(array_fill(0, (int) (0.3 * $sr), 0.0), self::vowel(200.0, 0.8));
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(tempo: $tempo));

        self::assertEqualsWithDelta(count($x) / $tempo, count($y), 0.01 * count($x));
        self::assertEqualsWithDelta(0.3 * $sr / $tempo, self::onset($y, 0), 0.01 * count($x));
        self::assertEqualsWithDelta(200.0, self::f0($y), 200.0 * 0.02);
    }

    public function testNoiseIsCopiedNotPitched(): void
    {
        $x = Signals::noise(0.5);
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(pitch: 1.15));

        self::assertEqualsWithDelta(Signals::rms($x), Signals::rms($y), Signals::rms($x) * 0.12);
    }

    public function testSilenceStaysSilentWhateverTheProfile(): void
    {
        $profile = new VoiceProfile(pitch: 1.1, formant: 0.95, tempo: 1.05, tiltLowDb: 3.0, tiltHighDb: -2.0, breath: 0.15);
        $y = (new VoiceShaper())->shape(array_fill(0, 22050, 0.0), $profile);

        self::assertEqualsWithDelta(22050 / 1.05, count($y), 1.0);
        self::assertSame(0.0, max(array_map('abs', $y)));
    }

    public function testIsDeterministic(): void
    {
        $x = self::vowel(170.0, 0.4);
        $p = new VoiceProfile(pitch: 0.93, formant: 1.04, tempo: 1.03, tiltLowDb: 1.5, tiltHighDb: -2.0, breath: 0.08);

        self::assertSame((new VoiceShaper())->shape($x, $p), (new VoiceShaper())->shape($x, $p));
    }

    public function testTiltShelvesLiftTheirBand(): void
    {
        $sr = Pcm::SAMPLE_RATE;
        $x = [];
        for ($i = 0; $i < $sr / 2; $i++) {
            $x[] = 0.3 * sin(2 * M_PI * 100.0 * $i / $sr) + 0.3 * sin(2 * M_PI * 1000.0 * $i / $sr) + 0.3 * sin(2 * M_PI * 8000.0 * $i / $sr);
        }
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(tiltLowDb: 3.0, tiltHighDb: -3.0));
        $db = static fn (float $hz): float => 20.0 * log10(Signals::at($y, $hz, 4410, 22050) / Signals::at($x, $hz, 4410, 22050));

        self::assertEqualsWithDelta(3.0, $db(100.0), 0.5);
        self::assertEqualsWithDelta(0.0, $db(1000.0), 0.6);
        self::assertEqualsWithDelta(-3.0, $db(8000.0), 0.5);
    }

    public function testBreathAddsNoiseThatFollowsTheVoice(): void
    {
        $sr = Pcm::SAMPLE_RATE;
        $x = array_merge(self::vowel(200.0, 0.4), array_fill(0, (int) (0.3 * $sr), 0.0));
        $y = (new VoiceShaper())->shape($x, new VoiceProfile(breath: 0.15));
        $voiced = [(int) (0.05 * $sr), (int) (0.35 * $sr)];

        // Between two harmonics there is now noise …
        self::assertGreaterThan(Signals::at($x, 6100.0, ...$voiced) * 3.0 + 1e-5, Signals::at($y, 6100.0, ...$voiced));
        // … but it is a whisper under the voice, and gone when the voice is.
        $added = array_map(static fn (float $a, float $b): float => $a - $b, $y, $x);
        self::assertLessThan(0.15 * Signals::rms($x, ...$voiced), Signals::rms($added, ...$voiced));
        self::assertLessThan(1e-3, Signals::rms($y, (int) (0.6 * $sr), count($y)), '−60 dB 200 ms after the voice');
    }

    /** @param list<float> $x first sample from $from where the signal gets loud */
    private static function onset(array $x, int $from): int
    {
        $peak = max(array_map('abs', $x));
        for ($i = $from, $n = count($x); $i < $n; $i++) {
            if (abs($x[$i]) > 0.2 * $peak) {
                return $i;
            }
        }
        return -1;
    }
}
