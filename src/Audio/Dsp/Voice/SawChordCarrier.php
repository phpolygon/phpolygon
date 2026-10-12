<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp\Voice;

/**
 * Default {@see VocoderCarrier}: a held chord of root, fifth and octave, each
 * a pair of band-limited (PolyBLEP) saw waves detuned by ±{@see $detune}, with
 * a short fade-in and fade-out. Start phases come from the seed, so the same
 * seed always gives the same carrier.
 */
final class SawChordCarrier implements VocoderCarrier
{
    /** Partials of the chord: [frequency ratio, gain]. */
    private const array PARTIALS = [[1.0, 0.5], [1.5, 0.3], [2.0, 0.3]];

    public function __construct(
        private readonly float $detune = 0.004,
        private readonly float $fadeSeconds = 0.005,
    ) {
        if ($detune < 0.0 || $detune >= 0.1) {
            throw new \InvalidArgumentException("detune $detune outside 0–0.1");
        }
    }

    public function render(float $rootHz, int $length, int $sampleRate, int $seed): array
    {
        if ($length <= 0) {
            return [];
        }
        $out = array_fill(0, $length, 0.0);
        $rng = ($seed & 0x7FFFFFFF) ?: 1;
        foreach (self::PARTIALS as [$ratio, $gain]) {
            foreach ([1.0 - $this->detune, 1.0 + $this->detune] as $spread) {
                $inc = $rootHz * $ratio * $spread / $sampleRate;
                if ($inc <= 0.0 || $inc >= 0.5) {
                    continue; // at or above Nyquist: nothing to add
                }
                $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
                $phase = $rng / 2147483648.0;
                $g = $gain * 0.5;
                for ($i = 0; $i < $length; $i++) {
                    $v = 2.0 * $phase - 1.0 - self::polyBlep($phase, $inc);
                    $out[$i] += $v * $g;
                    $phase += $inc;
                    if ($phase >= 1.0) {
                        $phase -= 1.0;
                    }
                }
            }
        }
        $fade = min(intdiv($length, 2), max(1, (int) round($this->fadeSeconds * $sampleRate)));
        for ($i = 0; $i < $fade; $i++) {
            $g = $i / $fade;
            $out[$i] *= $g;
            $out[$length - 1 - $i] *= $g;
        }
        return array_values($out);
    }

    /** PolyBLEP residual that rounds off the saw's reset at phase 0/1. */
    private static function polyBlep(float $t, float $dt): float
    {
        if ($t < $dt) {
            $t /= $dt;
            return $t + $t - $t * $t - 1.0;
        }
        if ($t > 1.0 - $dt) {
            $t = ($t - 1.0) / $dt;
            return $t * $t + $t + $t + 1.0;
        }
        return 0.0;
    }
}
