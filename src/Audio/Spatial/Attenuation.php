<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Spatial;

use InvalidArgumentException;

/**
 * Distance roll-off of a 3D sound source: full volume up to $minDistance,
 * silent from $maxDistance on, shaped by $curve in between.
 *
 * Inverse and exponential curves are "tapered": the raw curve is rescaled so
 * it is exactly 1 at the minimum and exactly 0 at the maximum radius – no step
 * at the edge, and a source beyond the maximum radius costs no audible level.
 * $rolloff steepens inverse/exponential curves (ignored by Linear and None).
 */
final class Attenuation
{
    /** Smallest minimum radius the inverse/exponential curves divide by. */
    private const float MIN_RADIUS_EPSILON = 1e-3;

    public function __construct(
        public readonly float $minDistance = 1.0,
        public readonly float $maxDistance = 50.0,
        public readonly AttenuationCurve $curve = AttenuationCurve::Inverse,
        public readonly float $rolloff = 1.0,
    ) {
        if (!is_finite($minDistance) || $minDistance < 0.0) {
            throw new InvalidArgumentException("Attenuation: minDistance must be >= 0, got {$minDistance}");
        }
        if (!is_finite($maxDistance) || $maxDistance <= $minDistance) {
            throw new InvalidArgumentException("Attenuation: maxDistance must be > minDistance, got {$maxDistance}");
        }
        if (!is_finite($rolloff) || $rolloff < 0.0) {
            throw new InvalidArgumentException("Attenuation: rolloff must be >= 0, got {$rolloff}");
        }
    }

    /** Linear gain (0–1) at $distance world units from the listener. */
    public function gain(float $distance): float
    {
        if ($this->curve === AttenuationCurve::None) {
            return 1.0;
        }
        if (is_nan($distance) || $distance <= $this->minDistance) {
            return 1.0;
        }
        if ($distance >= $this->maxDistance) {
            return 0.0;
        }

        if ($this->curve === AttenuationCurve::Linear) {
            return 1.0 - ($distance - $this->minDistance) / ($this->maxDistance - $this->minDistance);
        }

        $atMax = $this->raw($this->maxDistance);
        if ($atMax >= 1.0) {
            // rolloff 0: the raw curve is flat, fall back to the straight line.
            return 1.0 - ($distance - $this->minDistance) / ($this->maxDistance - $this->minDistance);
        }
        $g = ($this->raw($distance) - $atMax) / (1.0 - $atMax);
        return max(0.0, min(1.0, $g));
    }

    public function withRadius(float $minDistance, float $maxDistance): self
    {
        return new self($minDistance, $maxDistance, $this->curve, $this->rolloff);
    }

    private function raw(float $distance): float
    {
        $min = max(self::MIN_RADIUS_EPSILON, $this->minDistance);
        $d = max($min, $distance);
        return match ($this->curve) {
            AttenuationCurve::Inverse => $min / ($min + $this->rolloff * ($d - $min)),
            AttenuationCurve::Exponential => ($d / $min) ** (-$this->rolloff),
            default => 1.0,
        };
    }
}
