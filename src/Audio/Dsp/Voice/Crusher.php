<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

use PHPolygon\Audio\Dsp\Pcm;

/**
 * Broken-machine damage for a voice, scaled by one amount 0–1:
 *
 *  - sample-and-hold to a lower rate: hold 1 + round(7·amount) samples
 *    (0.7 → 6, ≈ 7 kHz at 44.1 kHz)
 *  - fewer bits: 16 − 12·amount (0.7 → ≈ 7.6 bit)
 *  - dropouts: per 40 ms block a chance of 0.3·amount to cut 15–60 ms, with
 *    2 ms fades so the gaps stutter rather than click
 *
 * Deterministic per seed (own LCG, no global random state).
 */
final class Crusher
{
    private const float BLOCK_S = 0.040;
    private const float FADE_S = 0.002;

    public function __construct(
        private readonly int $seed = 0xC0FFEE,
        public readonly int $sampleRate = Pcm::SAMPLE_RATE,
    ) {}

    /**
     * @param list<float> $x mono at {@see $sampleRate}
     * @return list<float>
     */
    public function apply(array $x, float $amount): array
    {
        if ($amount <= 0.0 || $x === []) {
            return $x;
        }
        $amount = min(1.0, $amount);
        $n = count($x);
        $hold = 1 + (int) round(7.0 * $amount);
        $levels = 2.0 ** (15.0 - 12.0 * $amount);

        $sr = $this->sampleRate;
        $block = (int) round(self::BLOCK_S * $sr);
        $fade = (int) round(self::FADE_S * $sr);
        $chance = 0.3 * $amount;
        $rng = $this->seed & 0x7FFFFFFF;
        $next = static function () use (&$rng): float {
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            return $rng / 2147483648.0;
        };
        $gain = array_fill(0, $n, 1.0);
        for ($s = 0; $s < $n; $s += $block) {
            if ($next() >= $chance) {
                continue;
            }
            $start = $s + (int) ($next() * $block);
            $len = (int) round((0.015 + 0.045 * $next()) * $sr);
            for ($k = -$fade; $k < $len + $fade; $k++) {
                $i = $start + $k;
                if ($i < 0 || $i >= $n) {
                    continue;
                }
                $g = $k < 0 ? -$k / $fade : ($k >= $len ? ($k - $len + 1) / $fade : 0.0);
                $gain[$i] = min($gain[$i], $g);
            }
        }
        // Dropouts first, then rate and bits: the fades are crushed too.
        $y = [];
        $held = 0.0;
        for ($i = 0; $i < $n; $i++) {
            if ($i % $hold === 0) {
                $held = round($x[$i] * $gain[$i] * $levels) / $levels;
            }
            $y[] = $held;
        }
        return $y;
    }
}
