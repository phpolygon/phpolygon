<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

/**
 * In-place iterative radix-2 FFT (forward, unnormalised: X[k] = Σ x[n]·e^(−2πikn/N))
 * with cached twiddle tables per size. Used for the long-term spectra of
 * {@see Voice\VoiceReference}.
 */
final class Fft
{
    /** @var array<int, array{list<float>, list<float>}> */
    private static array $twiddles = [];

    private function __construct() {}

    /**
     * @param array<int, float> $re
     * @param array<int, float> $im
     * @param-out array<int, float> $re
     * @param-out array<int, float> $im
     */
    public static function transform(array &$re, array &$im): void
    {
        $n = count($re);
        if ($n < 2 || ($n & ($n - 1)) !== 0 || count($im) !== $n) {
            throw new \InvalidArgumentException("FFT size must be a power of two, got $n");
        }
        for ($i = 1, $j = 0; $i < $n; $i++) {
            $bit = $n >> 1;
            for (; ($j & $bit) !== 0; $bit >>= 1) {
                $j ^= $bit;
            }
            $j ^= $bit;
            if ($i < $j) {
                $t = $re[$i];
                $re[$i] = $re[$j];
                $re[$j] = $t;
                $t = $im[$i];
                $im[$i] = $im[$j];
                $im[$j] = $t;
            }
        }
        [$cosT, $sinT] = self::$twiddles[$n] ??= self::table($n);
        for ($len = 2; $len <= $n; $len <<= 1) {
            $half = $len >> 1;
            $step = intdiv($n, $len);
            for ($i = 0; $i < $n; $i += $len) {
                for ($k = 0, $w = 0; $k < $half; $k++, $w += $step) {
                    $a = $i + $k;
                    $b = $a + $half;
                    $cr = $cosT[$w];
                    $ci = $sinT[$w];
                    $tr = $re[$b] * $cr - $im[$b] * $ci;
                    $ti = $re[$b] * $ci + $im[$b] * $cr;
                    $re[$b] = $re[$a] - $tr;
                    $im[$b] = $im[$a] - $ti;
                    $re[$a] += $tr;
                    $im[$a] += $ti;
                }
            }
        }
    }

    /** @return array{list<float>, list<float>} */
    private static function table(int $n): array
    {
        $c = [];
        $s = [];
        for ($k = 0; $k < $n / 2; $k++) {
            $c[] = cos(-2.0 * M_PI * $k / $n);
            $s[] = sin(-2.0 * M_PI * $k / $n);
        }
        return [$c, $s];
    }
}
