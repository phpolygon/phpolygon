<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

use PHPolygon\Math\Vec3;

/**
 * One placed instance of a scattered object, in terrain-local space.
 *
 * Rotation is an Euler XYZ triple in radians rather than a quaternion because
 * that is what the placement rules produce directly (a random yaw, optionally
 * tilted onto the surface normal); it is converted once when the transform
 * matrix is built.
 */
final readonly class ScatterInstance
{
    public function __construct(
        public Vec3 $position,
        public Vec3 $rotation,
        public float $scale,
    ) {}
}
