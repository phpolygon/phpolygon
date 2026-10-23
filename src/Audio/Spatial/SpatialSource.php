<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Spatial;

use PHPolygon\Math\Vec3;

/**
 * Everything a native spatial backend needs to place one 3D voice in the
 * world: world position and velocity (units/s), roll-off, the effective
 * Doppler factor (0 = off) and an optional facing direction with its cone.
 * AudioManager builds a fresh one whenever any of it changes.
 */
final class SpatialSource
{
    public function __construct(
        public readonly Vec3 $position,
        public readonly Vec3 $velocity,
        public readonly Attenuation $attenuation,
        public readonly float $doppler = 1.0,
        public readonly ?Vec3 $direction = null,
        public readonly ?SoundCone $cone = null,
    ) {}
}
