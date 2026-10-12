<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

/**
 * Makes a recording (e.g. a text-to-speech line) sound like a reference voice
 * ({@see VoiceReference}, e.g. a recorded base voice):
 *
 *  1. pitch: median f0 onto the target's (PSOLA), the melodic range moved
 *     halfway toward the target's (f_out = target · (f_in / median)^s)
 *  2. formants: warp by the weighted F1–F3 ratio ({@see VoiceReference::formantRatioTo()})
 *  3. timbre: a 13-band half-octave peaking EQ (100 Hz – 6.4 kHz, minimum
 *     phase) from the LTAS difference of the result to the target –
 *     smoothed, clamped to ±12 dB, band gains corrected for the overlap of
 *     neighbouring filters
 *
 * A recording matched to its own reference comes back unchanged. Timing is
 * kept (tempo 1). Clamps: pitch 0.5–2, formant 0.7–1.4.
 *
 * Mono at the shaper's sample rate; the detector must run at the same rate.
 */
final class VoiceMatcher
{
    public const float RANGE_SHARE = 0.5;
    public const float EQ_LIMIT_DB = 12.0;
    private const float EQ_Q = 2.87; // half-octave bandwidth
    private const int EQ_BANDS = 13; // 100 Hz · 2^(k/2), k = 0 … 12

    public function __construct(
        private readonly VoiceShaper $shaper = new VoiceShaper(),
        private readonly PitchDetector $detector = new PitchDetector(),
    ) {
        if ($shaper->sampleRate() !== $detector->sampleRate) {
            throw new \InvalidArgumentException(sprintf('shaper at %d Hz, detector at %d Hz', $shaper->sampleRate(), $detector->sampleRate));
        }
    }

    public function sampleRate(): int
    {
        return $this->detector->sampleRate;
    }

    /**
     * @param list<float> $pcm mono at {@see sampleRate()}
     * @return list<float>
     */
    public function match(array $pcm, VoiceReference $target): array
    {
        try {
            $in = VoiceReference::analyse($pcm, $this->detector);
        } catch (\InvalidArgumentException) {
            return $pcm; // nothing voiced: nothing to match
        }
        $plan = self::plan($in, $target);

        $shaped = $this->shaper->shape(
            $pcm,
            new VoiceProfile(pitch: $plan['pitch'], formant: $plan['formant']),
            $plan['rangeScale'],
            $in->medianF0,
        );
        $mid = $shaped === $pcm ? $in : VoiceReference::analyse($shaped, $this->detector);
        $gains = self::eqGains($mid, $target);
        return self::equalise($shaped, $gains, $this->detector->sampleRate);
    }

    /**
     * The pitch factor, formant warp and range exponent that take $in to $target.
     *
     * @return array{pitch: float, formant: float, rangeScale: float}
     */
    public static function plan(VoiceReference $in, VoiceReference $target): array
    {
        $pitch = max(0.5, min(2.0, $target->medianF0 / $in->medianF0));
        $formant = max(0.7, min(1.4, $in->formantRatioTo($target)));
        $spanIn = log($in->f0P90 / $in->f0P10);
        $spanTarget = log($target->f0P90 / $target->f0P10);
        $rangeScale = $spanIn > 1e-3
            ? max(0.25, min(3.0, 1.0 + self::RANGE_SHARE * ($spanTarget / $spanIn - 1.0)))
            : 1.0;
        return ['pitch' => $pitch, 'formant' => $formant, 'rangeScale' => $rangeScale];
    }

    /**
     * Desired EQ gain (dB) per half-octave band: the target-minus-current LTAS,
     * smoothed over neighbouring 1/6-octave bands, averaged over ±1/4 octave
     * and clamped. Bands below the voices' fundamental carry no voice and stay 0.
     *
     * @return list<float>
     */
    public static function eqGains(VoiceReference $current, VoiceReference $target): array
    {
        $centres = VoiceReference::bandCentres();
        $floor = 0.9 * max($current->f0P10, $target->f0P10);
        $diff = [];
        foreach ($centres as $k => $c) {
            $diff[] = $c < $floor || $c > 9000.0 ? null : $target->ltasDb[$k] - $current->ltasDb[$k];
        }
        $smooth = [];
        foreach ($diff as $k => $d) {
            if ($d === null) {
                $smooth[] = null;
                continue;
            }
            $sum = 2.0 * $d;
            $w = 2.0;
            foreach ([$k - 1, $k + 1] as $j) {
                if (isset($diff[$j])) {
                    $sum += $diff[$j];
                    $w += 1.0;
                }
            }
            $smooth[] = $sum / $w;
        }
        $gains = [];
        for ($b = 0; $b < self::EQ_BANDS; $b++) {
            $fc = 100.0 * 2.0 ** ($b / 2.0);
            $sum = 0.0;
            $n = 0;
            foreach ($centres as $k => $c) {
                if ($smooth[$k] !== null && abs(log($c / $fc, 2)) <= 0.25 + 1e-9) {
                    $sum += $smooth[$k];
                    $n++;
                }
            }
            $gains[] = $n > 0 ? max(-self::EQ_LIMIT_DB, min(self::EQ_LIMIT_DB, $sum / $n)) : 0.0;
        }
        return $gains;
    }

    /**
     * Cascade of peaking filters whose combined response hits $gains at the
     * band centres (Jacobi iteration on the filter gains).
     *
     * @param list<float> $y
     * @param list<float> $gains dB per half-octave band
     * @return list<float>
     */
    private static function equalise(array $y, array $gains, int $sr): array
    {
        $active = false;
        foreach ($gains as $g) {
            if (abs($g) >= 0.05) {
                $active = true;
                break;
            }
        }
        if (!$active) {
            return $y;
        }
        $centres = [];
        for ($b = 0; $b < self::EQ_BANDS; $b++) {
            $centres[] = 100.0 * 2.0 ** ($b / 2.0);
        }
        $set = $gains;
        for ($iter = 0; $iter < 12; $iter++) {
            $filters = array_map(static fn (float $fc, float $g): array => self::peaking($fc, $g, $sr), $centres, $set);
            foreach ($centres as $j => $f) {
                $actual = 0.0;
                foreach ($filters as $c) {
                    $actual += self::responseDb($c, $f, $sr);
                }
                $set[$j] = max(-18.0, min(18.0, $set[$j] + 0.8 * ($gains[$j] - $actual)));
            }
        }
        foreach ($centres as $j => $fc) {
            if (abs($set[$j]) < 1e-6) {
                continue;
            }
            [$b0, $b1, $b2, $a1, $a2] = self::peaking($fc, $set[$j], $sr);
            $x1 = $x2 = $y1 = $y2 = 0.0;
            foreach ($y as $i => $v) {
                $o = $b0 * $v + $b1 * $x1 + $b2 * $x2 - $a1 * $y1 - $a2 * $y2;
                $x2 = $x1;
                $x1 = $v;
                $y2 = $y1;
                $y1 = $o;
                $y[$i] = $o;
            }
        }
        return $y;
    }

    /** @return array{float, float, float, float, float} RBJ peaking EQ b0 b1 b2 a1 a2 */
    private static function peaking(float $fc, float $db, int $sr): array
    {
        $a = 10.0 ** ($db / 40.0);
        $w0 = 2.0 * M_PI * $fc / $sr;
        $alpha = sin($w0) / (2.0 * self::EQ_Q);
        $cs = cos($w0);
        $a0 = 1.0 + $alpha / $a;
        return [(1.0 + $alpha * $a) / $a0, -2.0 * $cs / $a0, (1.0 - $alpha * $a) / $a0, -2.0 * $cs / $a0, (1.0 - $alpha / $a) / $a0];
    }

    /** @param array{float, float, float, float, float} $c */
    private static function responseDb(array $c, float $hz, int $sr): float
    {
        $w = 2.0 * M_PI * $hz / $sr;
        $c1 = cos($w);
        $s1 = sin($w);
        $c2 = cos(2.0 * $w);
        $s2 = sin(2.0 * $w);
        $nr = $c[0] + $c[1] * $c1 + $c[2] * $c2;
        $ni = -$c[1] * $s1 - $c[2] * $s2;
        $dr = 1.0 + $c[3] * $c1 + $c[4] * $c2;
        $di = -$c[3] * $s1 - $c[4] * $s2;
        return 10.0 * log10(($nr * $nr + $ni * $ni) / ($dr * $dr + $di * $di));
    }
}
