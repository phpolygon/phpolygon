<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Dsp;

use InvalidArgumentException;

/**
 * One burst of filtered noise inside an {@see ImpactSynth}: the scrape of a heel,
 * the click of a second contact, the crack on top of a thud.
 *
 * White noise runs through the filters that are set (band-pass, then low-pass,
 * then high-pass) and is shaped by a linear attack and an exponential decay with
 * time constant $decaySeconds, starting $delaySeconds into the sound. $gain
 * scales the filtered noise as it comes out, so a narrow band is quieter than a
 * wide one at the same gain. The sample rate is only known at render time, so a
 * filter above its Nyquist frequency is held below it there
 * ({@see Pcm::belowNyquist()}), not refused here.
 */
final class ImpactNoise
{
    public function __construct(
        public readonly float $gain,
        public readonly float $decaySeconds,
        public readonly float $delaySeconds = 0.0,
        public readonly float $attackSeconds = 0.001,
        public readonly ?float $bandHz = null,
        public readonly float $bandQ = 1.0,
        public readonly ?float $lowPassHz = null,
        public readonly ?float $highPassHz = null,
    ) {
        if (!($decaySeconds > 0.0)) {
            throw new InvalidArgumentException("ImpactNoise: decaySeconds must be > 0, got {$decaySeconds}");
        }
        if ($delaySeconds < 0.0 || $attackSeconds < 0.0) {
            throw new InvalidArgumentException('ImpactNoise: delaySeconds and attackSeconds must be >= 0');
        }
        if (($bandHz !== null && !($bandHz > 0.0)) || !($bandQ > 0.0)) {
            throw new InvalidArgumentException('ImpactNoise: need bandHz > 0 and bandQ > 0');
        }
        if (($lowPassHz !== null && !($lowPassHz > 0.0)) || ($highPassHz !== null && !($highPassHz > 0.0))) {
            throw new InvalidArgumentException('ImpactNoise: need lowPassHz > 0 and highPassHz > 0');
        }
    }
}
