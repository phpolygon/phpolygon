<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Resampler;

/**
 * Turns a base recording into one speaker's voice ({@see VoiceProfile}):
 * pitch, formants, tempo, spectral tilt and breath.
 *
 *  1. Formant shift: band-limited resample by 1/formant – every frequency,
 *     resonances included, moves by the factor (and the clip gets shorter).
 *  2. TD-PSOLA on that: pitch marks one period apart on the waveform peaks of
 *     the voiced runs ({@see PitchDetector}); two-period Hann grains around the
 *     marks are laid out again at period/α (α = pitch / formant, which also
 *     undoes the resample's pitch change). The time map stretches the clip
 *     back and applies the tempo in the same pass. Grains keep their spectral
 *     envelope, so the formants stay where step 1 put them.
 *  3. Unvoiced stretches (s, f, breath, silence) are not pitched: WSOLA lays
 *     fixed 256-sample grains (11.6 ms at 44.1 kHz) at 50 % overlap, each
 *     picked within ±128 samples of its target where it best continues the
 *     previous one (at tempo 1 that is the source itself – a straight copy).
 *  4. All grains are normalised by their summed window (floored at 0.5, so the
 *     clip edges that only one grain tail covers fade instead of clicking), then low/high RBJ
 *     shelves (250 Hz / 3 kHz) tilt the spectrum and band-passed noise that
 *     follows the voice's envelope adds breath.
 *
 * Mono in/out at the detector's sample rate ({@see sampleRate()}). Deterministic.
 */
final class VoiceShaper
{
    private const int UNVOICED_HOP = 256;
    /** The formant resample only moves a few per cent: a shorter kernel does. */
    private const int FORMANT_ZERO_CROSSINGS = 8;
    private const int WSOLA_TOLERANCE = 128;
    private const float LOW_SHELF_HZ = 250.0;
    private const float HIGH_SHELF_HZ = 3000.0;
    private const int BREATH_SEED = 0x5EED;

    /** @var array<int, float> unit-RMS scale of the breath noise per sample rate */
    private static array $breathNorm = [];

    public function __construct(private readonly PitchDetector $detector = new PitchDetector()) {}

    public function sampleRate(): int
    {
        return $this->detector->sampleRate;
    }

    /**
     * $rangeScale stretches the melody around $pivotHz (default: the
     * recording's median f0) on top of the profile's pitch factor:
     * f_out = pitch · pivot · (f_in / pivot)^rangeScale. 1.0 leaves it alone.
     *
     * @param list<float> $pcm
     * @return list<float>
     */
    public function shape(array $pcm, VoiceProfile $profile, float $rangeScale = 1.0, ?float $pivotHz = null): array
    {
        if ($rangeScale <= 0.0 || $rangeScale > 3.0) {
            throw new \InvalidArgumentException("range scale $rangeScale outside 0–3");
        }
        if (($profile->shapesNothing() && $rangeScale === 1.0) || $pcm === []) {
            return $pcm;
        }
        $sr = $this->sampleRate();
        $n = count($pcm);
        $outLen = max(1, (int) round($n / $profile->tempo));
        $silent = true;
        foreach ($pcm as $v) {
            if ($v !== 0.0) {
                $silent = false;
                break;
            }
        }
        if ($silent) {
            return array_fill(0, $outLen, 0.0);
        }

        $y = $pcm;
        if ($profile->pitch !== 1.0 || $profile->formant !== 1.0 || $profile->tempo !== 1.0 || $rangeScale !== 1.0) {
            $src = $profile->formant === 1.0 ? $pcm : Resampler::stretch($pcm, 1.0 / $profile->formant, self::FORMANT_ZERO_CROSSINGS);
            $pivotSrc = $pivotHz === null ? null : $pivotHz * $profile->formant;
            $y = $this->psola($src, $profile->pitch / $profile->formant, count($src) / $outLen, $outLen, $rangeScale, $pivotSrc);
        }
        if ($profile->tiltLowDb !== 0.0) {
            $y = self::shelf($y, self::LOW_SHELF_HZ, $profile->tiltLowDb, false, $sr);
        }
        if ($profile->tiltHighDb !== 0.0) {
            $y = self::shelf($y, self::HIGH_SHELF_HZ, $profile->tiltHighDb, true, $sr);
        }
        if ($profile->breath > 0.0) {
            $y = self::breathe($y, $profile->breath, $sr);
        }
        return $y;
    }

    /**
     * Pitch-synchronous overlap-add with a time map.
     *
     * @param list<float> $src
     * @param float $alpha pitch factor applied to the voiced grains
     * @param float $rate source samples per output sample
     * @param float $rangeScale exponent on f0 / pivot (1.0 = plain factor)
     * @param float|null $pivot pivot f0 in the source, null = its median
     * @return list<float>
     */
    private function psola(array $src, float $alpha, float $rate, int $outLen, float $rangeScale = 1.0, ?float $pivot = null): array
    {
        $sr = $this->sampleRate();
        $track = $this->detector->track($src);
        $bend = $rangeScale - 1.0;
        $pivot ??= $track->medianF0();
        [$marks, $periods] = self::pitchMarks($src, $track, $sr);
        $markCount = count($marks);
        $n = count($src);

        $out = array_fill(0, $outLen, 0.0);
        $wsum = array_fill(0, $outLen, 0.0);
        $h = self::UNVOICED_HOP;
        $hann = [];
        for ($k = -$h; $k <= $h; $k++) {
            $hann[] = 0.5 + 0.5 * cos(M_PI * $k / $h);
        }

        $ts = 0.0;
        $lastSrc = null;   // source centre of the previous grain
        $lastAt = 0;       // where it was placed
        while ($ts < $outLen) {
            $ta = $ts * $rate;
            $m = $markCount > 0 && $track->isVoiced($ta) ? self::nearest($marks, $ta) : -1;
            if ($m >= 0 && abs($marks[$m] - $ta) <= $periods[$m]) {
                $p = $periods[$m];
                $c = $marks[$m];
                $at = (int) round($ts);
                $inv = M_PI / $p;
                $lo = max(-$p + 1, -$at, -$c);
                $hi = min($p - 1, $outLen - 1 - $at, $n - 1 - $c);
                for ($k = $lo; $k <= $hi; $k++) {
                    $w = 0.5 + 0.5 * cos($k * $inv);
                    $out[$at + $k] += $src[$c + $k] * $w;
                    $wsum[$at + $k] += $w;
                }
                $lastSrc = $c;
                $lastAt = $at;
                $local = $alpha;
                if ($bend !== 0.0 && $pivot > 0.0) {
                    $local *= ($sr / $p / $pivot) ** $bend;
                }
                $ts += $p / $local;
                continue;
            }

            $at = (int) round($ts);
            $target = (int) round($ta);
            $c = $target;
            if ($lastSrc !== null) {
                $natural = $lastSrc + ($at - $lastAt);
                if ($natural !== $target) {
                    $c = self::bestContinuation($src, $natural, $target);
                }
            }
            $lo = max(-$h, -$at, -$c);
            $hi = min($h, $outLen - 1 - $at, $n - 1 - $c);
            for ($k = $lo; $k <= $hi; $k++) {
                $w = $hann[$k + $h];
                $out[$at + $k] += $src[$c + $k] * $w;
                $wsum[$at + $k] += $w;
            }
            $lastSrc = $c;
            $lastAt = $at;
            $ts += $h;
        }

        for ($i = 0; $i < $outLen; $i++) {
            $w = $wsum[$i];
            $out[$i] /= $w > 0.5 ? $w : 0.5;
        }
        return array_values($out);
    }

    /**
     * Pitch marks on the voiced runs: one per period, each on the peak of the
     * low-passed waveform within ±15 % of where the previous period predicts it.
     *
     * @param list<float> $src
     * @return array{list<int>, list<int>} marks and the local period (samples) at each
     */
    private static function pitchMarks(array $src, PitchTrack $track, int $sr): array
    {
        $smooth = self::zeroPhaseLowPass($src, 900.0, $sr);
        $n = count($src);
        $hop = $track->hop;
        $frames = count($track->f0);
        $marks = [];
        $periods = [];
        for ($f = 0; $f < $frames;) {
            if ($track->f0[$f] <= 0.0) {
                $f++;
                continue;
            }
            $g = $f;
            while ($g < $frames && $track->f0[$g] > 0.0) {
                $g++;
            }
            $start = max(0, $f * $hop - intdiv($hop, 2));
            $end = min($n - 1, ($g - 1) * $hop + intdiv($hop, 2));
            $f = $g;

            $p = $sr / $track->f0At($start + $hop / 2);
            $m = self::peak($smooth, $start, (int) min($end, $start + $p));
            $run = [];
            while ($m <= $end) {
                $run[] = $m;
                $f0 = $track->f0At($m);
                $p = $sr / ($f0 > 0.0 ? $f0 : $sr / $p);
                $expect = (int) round($m + $p);
                $tol = (int) max(1, round(0.15 * $p));
                $next = self::peak($smooth, $expect - $tol, min($n - 1, $expect + $tol));
                if ($next <= $m) {
                    break;
                }
                $m = $next;
            }
            $count = count($run);
            for ($i = 0; $i < $count; $i++) {
                $marks[] = $run[$i];
                $periods[] = $i + 1 < $count
                    ? max(2, $run[$i + 1] - $run[$i])
                    : ($i > 0 ? max(2, $run[$i] - $run[$i - 1]) : max(2, (int) round($p)));
            }
        }
        return [$marks, $periods];
    }

    /** @param list<float> $x */
    private static function peak(array $x, int $from, int $to): int
    {
        $from = max(0, $from);
        $best = $from;
        for ($i = $from + 1; $i <= $to; $i++) {
            if ($x[$i] > $x[$best]) {
                $best = $i;
            }
        }
        return $best;
    }

    /** @param list<int> $marks index of the mark nearest to $pos */
    private static function nearest(array $marks, float $pos): int
    {
        $lo = 0;
        $hi = count($marks) - 1;
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($marks[$mid] < $pos) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return abs($marks[$lo] - $pos) <= abs($marks[$hi] - $pos) ? $lo : $hi;
    }

    /**
     * WSOLA search: the grain centre within ±tolerance of $target whose
     * neighbourhood best matches the natural continuation at $natural
     * (coarse pass every 4 samples on a 4× thinned grain, then ±3 fine).
     *
     * @param list<float> $src
     */
    private static function bestContinuation(array $src, int $natural, int $target): int
    {
        $n = count($src);
        $h = self::UNVOICED_HOP;
        $score = static function (int $c) use ($src, $natural, $n, $h): float {
            $dot = 0.0;
            $e = 0.0;
            for ($k = -$h; $k <= $h; $k += 4) {
                $a = $c + $k;
                $b = $natural + $k;
                if ($a < 0 || $b < 0 || $a >= $n || $b >= $n) {
                    continue;
                }
                $dot += $src[$a] * $src[$b];
                $e += $src[$a] * $src[$a];
            }
            return $e > 0.0 ? $dot / sqrt($e) : -INF;
        };
        $tol = self::WSOLA_TOLERANCE;
        if (abs($natural - $target) <= $tol) {
            // The natural continuation is a valid pick and matches itself best.
            return $natural;
        }
        $best = $target;
        $bestScore = -INF;
        for ($c = $target - $tol; $c <= $target + $tol; $c += 4) {
            $s = $score($c);
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $c;
            }
        }
        $centre = $best;
        for ($c = $centre - 3; $c <= $centre + 3; $c++) {
            $s = $score($c);
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $c;
            }
        }
        return max(0, min($n - 1, $best));
    }

    /**
     * Two one-pole passes forward and back – no phase shift, so peaks stay put.
     *
     * @param list<float> $x
     * @return list<float>
     */
    private static function zeroPhaseLowPass(array $x, float $hz, int $sr): array
    {
        $a = 1.0 - exp(-2.0 * M_PI * $hz / $sr);
        $n = count($x);
        for ($pass = 0; $pass < 2; $pass++) {
            $s = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $s += $a * ($x[$i] - $s);
                $x[$i] = $s;
            }
            $s = 0.0;
            for ($i = $n - 1; $i >= 0; $i--) {
                $s += $a * ($x[$i] - $s);
                $x[$i] = $s;
            }
        }
        return array_values($x);
    }

    /**
     * RBJ shelf (S = 1).
     *
     * @param list<float> $x
     * @return list<float>
     */
    private static function shelf(array $x, float $hz, float $db, bool $high, int $sr): array
    {
        $amp = 10.0 ** ($db / 40.0);
        $w0 = 2.0 * M_PI * $hz / $sr;
        $cs = cos($w0);
        $alpha = sin($w0) / 2.0 * M_SQRT2;
        $sq = 2.0 * sqrt($amp) * $alpha;
        $sign = $high ? -1.0 : 1.0;
        $b0 = $amp * (($amp + 1.0) - $sign * ($amp - 1.0) * $cs + $sq);
        $b1 = 2.0 * $sign * $amp * (($amp - 1.0) - $sign * ($amp + 1.0) * $cs);
        $b2 = $amp * (($amp + 1.0) - $sign * ($amp - 1.0) * $cs - $sq);
        $a0 = ($amp + 1.0) + $sign * ($amp - 1.0) * $cs + $sq;
        $a1 = -2.0 * $sign * (($amp - 1.0) + $sign * ($amp + 1.0) * $cs);
        $a2 = ($amp + 1.0) + $sign * ($amp - 1.0) * $cs - $sq;
        return self::biquad($x, $b0 / $a0, $b1 / $a0, $b2 / $a0, $a1 / $a0, $a2 / $a0);
    }

    /**
     * @param list<float> $x
     * @return list<float>
     */
    private static function biquad(array $x, float $b0, float $b1, float $b2, float $a1, float $a2): array
    {
        $x1 = $x2 = $y1 = $y2 = 0.0;
        foreach ($x as $i => $v) {
            $y = $b0 * $v + $b1 * $x1 + $b2 * $x2 - $a1 * $y1 - $a2 * $y2;
            $x2 = $x1;
            $x1 = $v;
            $y2 = $y1;
            $y1 = $y;
            $x[$i] = $y;
        }
        return $x;
    }

    /**
     * Band-passed (1.2–7 kHz) noise scaled by the voice's envelope.
     *
     * @param list<float> $x
     * @return list<float>
     */
    private static function breathe(array $x, float $amount, int $sr): array
    {
        $hp = self::rbjCoefficients(1200.0, true, $sr);
        $lp = self::rbjCoefficients(7000.0, false, $sr);
        // Unit-RMS scale of the band noise, measured once per rate on one second of it.
        self::$breathNorm[$sr] ??= (static function () use ($hp, $lp, $sr): float {
            $rng = self::BREATH_SEED;
            $s1 = $s2 = [0.0, 0.0, 0.0, 0.0];
            $sum = 0.0;
            for ($i = 0; $i < $sr; $i++) {
                $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
                $v = self::step(self::step(($rng / 1073741824.0) - 1.0, $hp, $s1), $lp, $s2);
                $sum += $v * $v;
            }
            return 1.0 / sqrt($sum / $sr);
        })();

        $atk = exp(-1.0 / (0.005 * $sr));
        $rel = exp(-1.0 / (0.040 * $sr));
        $env = 0.0;
        $gain = $amount * 0.5 * self::$breathNorm[$sr];
        $rng = self::BREATH_SEED;
        $s1 = $s2 = [0.0, 0.0, 0.0, 0.0];
        foreach ($x as $i => $v) {
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            $noise = self::step(self::step(($rng / 1073741824.0) - 1.0, $hp, $s1), $lp, $s2);
            $a = abs($v);
            $env = $a > $env ? $atk * $env + (1.0 - $atk) * $a : $rel * $env + (1.0 - $rel) * $a;
            if ($env < 1e-9) {
                $env = 0.0;
            }
            $x[$i] = $v + $noise * $env * $gain;
        }
        return $x;
    }

    /**
     * One biquad sample; $s = [x1, x2, y1, y2].
     *
     * @param array{float, float, float, float, float} $c
     * @param array{float, float, float, float} $s
     */
    private static function step(float $v, array $c, array &$s): float
    {
        $y = $c[0] * $v + $c[1] * $s[0] + $c[2] * $s[1] - $c[3] * $s[2] - $c[4] * $s[3];
        $s = [$v, $s[0], $y, $s[2]];
        return $y;
    }

    /** @return array{float, float, float, float, float} Butterworth (Q 0.707) b0 b1 b2 a1 a2 */
    private static function rbjCoefficients(float $hz, bool $high, int $sr): array
    {
        $w0 = 2.0 * M_PI * $hz / $sr;
        $cs = cos($w0);
        $alpha = sin($w0) / (2.0 * M_SQRT1_2);
        $a0 = 1.0 + $alpha;
        $b0 = ($high ? (1.0 + $cs) : (1.0 - $cs)) / 2.0 / $a0;
        $b1 = ($high ? -(1.0 + $cs) : (1.0 - $cs)) / $a0;
        return [$b0, $b1, $b0, -2.0 * $cs / $a0, (1.0 - $alpha) / $a0];
    }
}
