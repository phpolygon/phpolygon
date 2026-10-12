<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

/**
 * Band-limited resampling with a Kaiser-windowed sinc (β 9, 16 zero
 * crossings, cut-off at 90 % of the lower Nyquist). The kernel lives in a
 * finely sampled table (512 steps per zero crossing, linear interpolation),
 * so any ratio – 48 → 44.1 kHz as well as the few per cent of a formant
 * shift – costs one table lookup per tap.
 *
 * Mono, deterministic. Positions outside the signal count as silence.
 */
final class Resampler
{
    private const int STEPS = 512;
    private const float BETA = 9.0;
    private const float CUTOFF = 0.9;

    /** @var array<int, list<float>> kernel tables by zero-crossing count */
    private static array $tables = [];

    private function __construct() {}

    /**
     * Sample-rate conversion: the same sound at a new rate.
     *
     * @param list<float> $x
     * @return list<float>
     */
    public static function convert(array $x, int $fromRate, int $toRate): array
    {
        if ($fromRate <= 0 || $toRate <= 0) {
            throw new \InvalidArgumentException("invalid sample rate $fromRate → $toRate");
        }
        return $fromRate === $toRate ? $x : self::stretch($x, $toRate / $fromRate);
    }

    /**
     * Stretch the waveform by $factor: the result is $factor times as long and
     * every frequency is divided by $factor (2.0 = an octave down, twice as long).
     *
     * @param list<float> $x
     * @return list<float>
     */
    public static function stretch(array $x, float $factor, int $zeroCrossings = 16): array
    {
        if ($factor <= 0.0) {
            throw new \InvalidArgumentException("stretch factor must be positive, got $factor");
        }
        if ($factor === 1.0 || $x === []) {
            return $x;
        }
        $n = count($x);
        $m = (int) round($n * $factor);
        $tab = self::table($zeroCrossings);
        $fc = min(1.0, $factor) * self::CUTOFF;
        $half = $zeroCrossings / $fc;
        $scale = $fc * self::STEPS;
        $last = $n - 1;

        $out = [];
        for ($i = 0; $i < $m; $i++) {
            $p = $i / $factor;
            $lo = (int) ceil($p - $half);
            $hi = (int) floor($p + $half);
            if ($lo < 0) {
                $lo = 0;
            }
            if ($hi > $last) {
                $hi = $last;
            }
            $acc = 0.0;
            for ($k = $lo; $k <= $hi; $k++) {
                $u = abs($p - $k) * $scale;
                $j = (int) $u;
                $t0 = $tab[$j];
                $acc += $x[$k] * ($t0 + ($u - $j) * ($tab[$j + 1] - $t0));
            }
            $out[] = $acc * $fc;
        }
        return $out;
    }

    /** @return list<float> K(u) = sinc(u)·kaiser(u/Z), u = 0 … Z in 1/STEPS, two zeros of padding */
    private static function table(int $zeroCrossings): array
    {
        if (isset(self::$tables[$zeroCrossings])) {
            return self::$tables[$zeroCrossings];
        }
        $len = $zeroCrossings * self::STEPS;
        $i0b = self::besselI0(self::BETA);
        $tab = [];
        for ($j = 0; $j < $len; $j++) {
            $u = $j / self::STEPS;
            $sinc = $j === 0 ? 1.0 : sin(M_PI * $u) / (M_PI * $u);
            $r = $u / $zeroCrossings;
            $tab[] = $sinc * self::besselI0(self::BETA * sqrt(max(0.0, 1.0 - $r * $r))) / $i0b;
        }
        // K(Z) = 0 exactly, then the padding.
        $tab[] = 0.0;
        $tab[] = 0.0;
        $tab[] = 0.0;
        return self::$tables[$zeroCrossings] = $tab;
    }

    private static function besselI0(float $x): float
    {
        $sum = 1.0;
        $term = 1.0;
        $h = $x / 2.0;
        for ($k = 1; $k < 50; $k++) {
            $term *= ($h / $k) ** 2;
            $sum += $term;
            if ($term < 1e-14 * $sum) {
                break;
            }
        }
        return $sum;
    }
}
