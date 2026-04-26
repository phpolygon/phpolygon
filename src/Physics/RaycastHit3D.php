<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec3;

/**
 * Result of a 3D raycast hit.
 */
readonly class RaycastHit3D
{
    public function __construct(
        public int $entityId,
        public Vec3 $point,
        public Vec3 $normal,
        public float $distance,
    ) {}
}
