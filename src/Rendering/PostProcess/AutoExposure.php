<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * Eye adaptation of the image chain ({@see ImageLook::$autoExposure}). The GPU measures the
 * centre-weighted mean log2 luminance of the linear scene colour (before bloom), adapts to
 * it over time and scales the image by
 *
 *     exposure = 2 ^ clamp(strength * (log2(reference) - adapted), minEv, maxEv)
 *
 * so a frame whose mean luminance equals `$reference` keeps exposure 1 - the value the
 * look was tuned at. `$strength` below 1 adapts only part of the way (a night stays a
 * night). Nothing is read back to the CPU. Only on the HDR scene path.
 */
final class AutoExposure
{
    /**
     * @param float $reference linear mean luminance that gets exposure 1 (a game measures it at its reference view)
     * @param float $strength  0..1 share of the difference the exposure follows
     * @param float $minEv     darkest correction, EV (negative darkens)
     * @param float $maxEv     brightest correction, EV
     * @param float $speedUp   adaptation rate towards a brighter scene, 1/s (exponential)
     * @param float $speedDown adaptation rate towards a darker scene, 1/s; eyes adapt to the dark slower
     */
    public function __construct(
        public readonly float $reference = 0.18,
        public readonly float $strength = 1.0,
        public readonly float $minEv = -2.0,
        public readonly float $maxEv = 2.0,
        public readonly float $speedUp = 3.0,
        public readonly float $speedDown = 1.0,
    ) {
        if ($reference <= 0.0) {
            throw new \InvalidArgumentException('AutoExposure reference must be > 0');
        }
        if ($minEv > $maxEv) {
            throw new \InvalidArgumentException('AutoExposure minEv must not exceed maxEv');
        }
    }

    public static function lerp(self $a, self $b, float $t): self
    {
        return new self(
            exp(log($a->reference) + (log($b->reference) - log($a->reference)) * $t),
            $a->strength + ($b->strength - $a->strength) * $t,
            $a->minEv + ($b->minEv - $a->minEv) * $t,
            $a->maxEv + ($b->maxEv - $a->maxEv) * $t,
            $a->speedUp + ($b->speedUp - $a->speedUp) * $t,
            $a->speedDown + ($b->speedDown - $a->speedDown) * $t,
        );
    }

    /** The exposure the GPU state arrives at for a mean luminance; the shader computes the same. */
    public function exposureFor(float $meanLuminance): float
    {
        $ev = $this->strength * (log($this->reference, 2) - log(max($meanLuminance, 1e-6), 2));
        return 2.0 ** max($this->minEv, min($this->maxEv, $ev));
    }
}
