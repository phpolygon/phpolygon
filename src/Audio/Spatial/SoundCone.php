<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Spatial;

use InvalidArgumentException;
use PHPolygon\Math\Vec3;

/**
 * Directional sound source: full volume inside the inner cone around the
 * source's facing direction, $outerGain outside the outer cone, linear in
 * the cosine of the angle in between (miniaudio's / OpenAL's model).
 *
 * Angles are the FULL opening angles in radians (0…2π, outer ≥ inner): an
 * inner angle of π/2 is a 90° cone, ±45° around the facing direction. An
 * inner angle of 2π means "no cone" (omnidirectional).
 */
final class SoundCone
{
    public const float FULL_CIRCLE = 2.0 * M_PI;

    public function __construct(
        public readonly float $innerAngle,
        public readonly float $outerAngle,
        public readonly float $outerGain = 0.0,
    ) {
        if (!is_finite($innerAngle) || $innerAngle < 0.0 || $innerAngle > self::FULL_CIRCLE) {
            throw new InvalidArgumentException("SoundCone: innerAngle must be 0…2π, got {$innerAngle}");
        }
        if (!is_finite($outerAngle) || $outerAngle < $innerAngle || $outerAngle > self::FULL_CIRCLE) {
            throw new InvalidArgumentException("SoundCone: outerAngle must be innerAngle…2π, got {$outerAngle}");
        }
        if (!is_finite($outerGain) || $outerGain < 0.0 || $outerGain > 1.0) {
            throw new InvalidArgumentException("SoundCone: outerGain must be 0…1, got {$outerGain}");
        }
    }

    /** The same cone with the angles given in degrees. */
    public static function degrees(float $innerDegrees, float $outerDegrees, float $outerGain = 0.0): self
    {
        return new self(deg2rad($innerDegrees), deg2rad($outerDegrees), $outerGain);
    }

    /**
     * Gain for a listener seen from the source: $facing is the source's
     * direction, $toListener the vector from the source to the listener
     * (neither needs to be normalised). 1 when either is zero.
     */
    public function gain(Vec3 $facing, Vec3 $toListener): float
    {
        if ($this->innerAngle >= self::FULL_CIRCLE - 1e-6) {
            return 1.0;
        }
        $lf = $facing->length();
        $lt = $toListener->length();
        if ($lf <= 1e-9 || $lt <= 1e-9) {
            return 1.0;
        }
        $d = $facing->dot($toListener) / ($lf * $lt);
        $cutoffInner = cos($this->innerAngle * 0.5);
        $cutoffOuter = cos($this->outerAngle * 0.5);
        if ($d > $cutoffInner) {
            return 1.0;
        }
        if ($d > $cutoffOuter) {
            return $this->outerGain + (1.0 - $this->outerGain) * ($d - $cutoffOuter) / ($cutoffInner - $cutoffOuter);
        }
        return $this->outerGain;
    }
}
