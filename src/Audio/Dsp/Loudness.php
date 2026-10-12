<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

/**
 * Integrated loudness after ITU-R BS.1770 (LUFS): K-weighting (high shelf +
 * high-pass), 400 ms blocks with 75 % overlap, absolute gate at −70 LUFS and
 * relative gate 10 LU below the ungated mean. Good to a few tenths of a dB
 * against ffmpeg's ebur128 – enough to level clips against each other.
 */
final class Loudness
{
    private function __construct() {}

    /**
     * @param list<float> $left
     * @param list<float> $right
     */
    public static function integrated(array $left, array $right, int $sampleRate = Pcm::SAMPLE_RATE): float
    {
        $ms = [self::blockPowers($left, $sampleRate), self::blockPowers($right, $sampleRate)];
        $z = [];
        for ($b = 0, $blocks = count($ms[0]); $b < $blocks; $b++) {
            $z[] = $ms[0][$b] + $ms[1][$b];
        }
        return self::gated($z);
    }

    /**
     * Integrated loudness of a single channel (a mono clip played as mono).
     *
     * @param list<float> $x
     */
    public static function integratedMono(array $x, int $sampleRate = Pcm::SAMPLE_RATE): float
    {
        return self::gated(self::blockPowers($x, $sampleRate));
    }

    /**
     * Absolute and relative gating over summed block powers.
     *
     * @param list<float> $z
     */
    private static function gated(array $z): float
    {
        if ($z === []) {
            return -70.0;
        }
        $lufs = static fn (float $p): float => -0.691 + 10.0 * log10(max(1e-12, $p));

        $abs = array_values(array_filter($z, static fn (float $p): bool => $lufs($p) > -70.0));
        if ($abs === []) {
            return -70.0;
        }
        $relGate = $lufs(array_sum($abs) / count($abs)) - 10.0;
        $rel = array_values(array_filter($abs, static fn (float $p): bool => $lufs($p) > $relGate));
        if ($rel === []) {
            return -70.0;
        }
        return $lufs(array_sum($rel) / count($rel));
    }

    /**
     * Mean square of the K-weighted signal per 400 ms block (100 ms hop).
     *
     * @param list<float> $x
     * @return list<float>
     */
    private static function blockPowers(array $x, int $sampleRate): array
    {
        // Stage 1: high shelf (+4 dB above ~1.7 kHz).
        $f0 = 1681.974450955533;
        $g = 3.999843853973347;
        $q = 0.7071752369554196;
        $k = tan(M_PI * $f0 / $sampleRate);
        $vh = 10.0 ** ($g / 20.0);
        $vb = $vh ** 0.4996667741545416;
        $a0 = 1.0 + $k / $q + $k * $k;
        $b0 = ($vh + $vb * $k / $q + $k * $k) / $a0;
        $b1 = 2.0 * ($k * $k - $vh) / $a0;
        $b2 = ($vh - $vb * $k / $q + $k * $k) / $a0;
        $a1 = 2.0 * ($k * $k - 1.0) / $a0;
        $a2 = (1.0 - $k / $q + $k * $k) / $a0;
        // Stage 2: high-pass (~38 Hz).
        $f0h = 38.13547087602444;
        $qh = 0.5003270373238773;
        $kh = tan(M_PI * $f0h / $sampleRate);
        $den = 1.0 + $kh / $qh + $kh * $kh;
        $c1 = 2.0 * ($kh * $kh - 1.0) / $den;
        $c2 = (1.0 - $kh / $qh + $kh * $kh) / $den;

        $hop = (int) round(0.1 * $sampleRate);
        $n = count($x);
        $hopSums = [];
        $acc = 0.0;
        $x1 = $x2 = $y1 = $y2 = 0.0;
        $z1 = $z2 = $w1 = $w2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $in = $x[$i];
            $y = $b0 * $in + $b1 * $x1 + $b2 * $x2 - $a1 * $y1 - $a2 * $y2;
            $x2 = $x1;
            $x1 = $in;
            $y2 = $y1;
            $y1 = $y;
            $w = $y - 2.0 * $z1 + $z2 - $c1 * $w1 - $c2 * $w2;
            $z2 = $z1;
            $z1 = $y;
            $w2 = $w1;
            $w1 = $w;
            $acc += $w * $w;
            if (($i + 1) % $hop === 0) {
                $hopSums[] = $acc;
                $acc = 0.0;
            }
        }
        $blocks = [];
        for ($h = 3; $h < count($hopSums); $h++) {
            $blocks[] = ($hopSums[$h] + $hopSums[$h - 1] + $hopSums[$h - 2] + $hopSums[$h - 3]) / (4 * $hop);
        }
        return $blocks;
    }
}
