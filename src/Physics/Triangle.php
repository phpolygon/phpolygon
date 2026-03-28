<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec3;

/**
 * Immutable triangle with precomputed normal.
 */
readonly class Triangle
{
    public Vec3 $normal;

    public function __construct(
        public Vec3 $v0,
        public Vec3 $v1,
        public Vec3 $v2,
    ) {
        $edge1 = $v1->sub($v0);
        $edge2 = $v2->sub($v0);
        $cross = $edge1->cross($edge2);
        $len = $cross->length();
        $this->normal = $len > 1e-10 ? $cross->div($len) : Vec3::zero();
    }

    /**
     * A triangle is degenerate if its vertices are collinear (zero-area).
     */
    public function isDegenerate(): bool
    {
        return $this->normal->equals(Vec3::zero());
    }
}
