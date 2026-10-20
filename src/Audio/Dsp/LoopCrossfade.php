<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;

/**
 * Seamless loops: fold the last $crossfade samples of a buffer over its
 * first ones, so the end runs into the start without a click or a gap.
 *
 * For input x of length n and crossfade c the loop is n − c long:
 *   loop[i] = x[i] · in(i) + x[n − c + i] · out(i)   for i < c
 *   loop[i] = x[i]                                    for i ≥ c
 * The loop's last sample is x[n − c − 1] and wraps to loop[0] ≈ x[n − c] –
 * the very next source sample – so the seam is as smooth as the source.
 * Equal-power fades (sin/cos, default) keep the level of uncorrelated
 * material (noise, rain, crowds); linear fades suit correlated material
 * (a sustained tone in phase).
 */
final class LoopCrossfade
{
    private function __construct() {}

    /**
     * @param list<float> $pcm
     * @return list<float>
     */
    public static function make(array $pcm, int $crossfade, bool $equalPower = true): array
    {
        $n = count($pcm);
        if ($crossfade < 1 || 2 * $crossfade > $n) {
            throw new InvalidArgumentException("LoopCrossfade: crossfade must be in [1, n/2], got {$crossfade} for {$n} samples");
        }
        $tail = $n - $crossfade;
        $out = [];
        for ($i = 0; $i < $crossfade; $i++) {
            // Fades centred on sample midpoints: in(0) > 0, out(c − 1) > 0.
            $u = ($i + 0.5) / $crossfade;
            if ($equalPower) {
                $in = sin(0.5 * M_PI * $u);
                $fo = cos(0.5 * M_PI * $u);
            } else {
                $in = $u;
                $fo = 1.0 - $u;
            }
            $out[] = $pcm[$i] * $in + $pcm[$tail + $i] * $fo;
        }
        for ($i = $crossfade; $i < $tail; $i++) {
            $out[] = $pcm[$i];
        }
        return $out;
    }

    /**
     * {@see make()} with the crossfade in seconds.
     *
     * @param list<float> $pcm
     * @return list<float>
     */
    public static function makeSeconds(array $pcm, float $seconds, int $sampleRate = Pcm::SAMPLE_RATE, bool $equalPower = true): array
    {
        return self::make($pcm, max(1, (int) round($seconds * $sampleRate)), $equalPower);
    }

    /**
     * Both channels with the same seam.
     *
     * @param list<float> $left
     * @param list<float> $right
     * @return array{list<float>, list<float>}
     */
    public static function makeStereo(array $left, array $right, int $crossfade, bool $equalPower = true): array
    {
        if (count($left) !== count($right)) {
            throw new InvalidArgumentException('LoopCrossfade: channels differ in length');
        }
        return [self::make($left, $crossfade, $equalPower), self::make($right, $crossfade, $equalPower)];
    }

    /**
     * Largest sample-to-sample step at the loop point (last → first) relative
     * to the largest step inside the buffer – ≤ 1 means the seam is no
     * rougher than the material itself.
     *
     * @param list<float> $loop
     */
    public static function seamRoughness(array $loop): float
    {
        $n = count($loop);
        if ($n < 2) {
            return 0.0;
        }
        $maxStep = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $maxStep = max($maxStep, abs($loop[$i] - $loop[$i - 1]));
        }
        $seam = abs($loop[0] - $loop[$n - 1]);
        return $maxStep > 0.0 ? $seam / $maxStep : ($seam > 0.0 ? INF : 0.0);
    }
}
