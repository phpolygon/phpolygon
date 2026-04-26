<?php

declare(strict_types=1);

namespace PHPolygon\Math;

/**
 * An infinite ray defined by an origin point and a direction.
 */
readonly class Ray
{
    public Vec3 $direction;

    public function __construct(
        public Vec3 $origin,
        Vec3 $direction,
    ) {
        $len = $direction->length();
        $this->direction = $len > 1e-10 ? $direction->mul(1.0 / $len) : new Vec3(0.0, 0.0, -1.0);
    }

    /**
     * Get a point along the ray at the given distance.
     */
    public function pointAt(float $t): Vec3
    {
        return $this->origin->add($this->direction->mul($t));
    }

    /**
     * Intersect this ray with an axis-aligned bounding box.
     *
     * @return float|null Distance along the ray to the hit point, or null if no hit.
     */
    public function intersectsAABB(Vec3 $min, Vec3 $max): ?float
    {
        $tMin = -PHP_FLOAT_MAX;
        $tMax = PHP_FLOAT_MAX;

        // X slab
        if (abs($this->direction->x) > 1e-10) {
            $invD = 1.0 / $this->direction->x;
            $t1 = ($min->x - $this->origin->x) * $invD;
            $t2 = ($max->x - $this->origin->x) * $invD;
            if ($t1 > $t2) { $tmp = $t1; $t1 = $t2; $t2 = $tmp; }
            $tMin = max($tMin, $t1);
            $tMax = min($tMax, $t2);
        } elseif ($this->origin->x < $min->x || $this->origin->x > $max->x) {
            return null;
        }

        // Y slab
        if (abs($this->direction->y) > 1e-10) {
            $invD = 1.0 / $this->direction->y;
            $t1 = ($min->y - $this->origin->y) * $invD;
            $t2 = ($max->y - $this->origin->y) * $invD;
            if ($t1 > $t2) { $tmp = $t1; $t1 = $t2; $t2 = $tmp; }
            $tMin = max($tMin, $t1);
            $tMax = min($tMax, $t2);
        } elseif ($this->origin->y < $min->y || $this->origin->y > $max->y) {
            return null;
        }

        // Z slab
        if (abs($this->direction->z) > 1e-10) {
            $invD = 1.0 / $this->direction->z;
            $t1 = ($min->z - $this->origin->z) * $invD;
            $t2 = ($max->z - $this->origin->z) * $invD;
            if ($t1 > $t2) { $tmp = $t1; $t1 = $t2; $t2 = $tmp; }
            $tMin = max($tMin, $t1);
            $tMax = min($tMax, $t2);
        } elseif ($this->origin->z < $min->z || $this->origin->z > $max->z) {
            return null;
        }

        if ($tMax < 0.0 || $tMin > $tMax) {
            return null;
        }

        return $tMin >= 0.0 ? $tMin : $tMax;
    }

    /**
     * Intersect this ray with a plane defined by a point and normal.
     *
     * @return float|null Distance along the ray, or null if parallel.
     */
    public function intersectsPlane(Vec3 $planePoint, Vec3 $planeNormal): ?float
    {
        $denom = $this->direction->dot($planeNormal);
        if (abs($denom) < 1e-10) {
            return null;
        }

        $t = $planePoint->sub($this->origin)->dot($planeNormal) / $denom;
        return $t >= 0.0 ? $t : null;
    }

    /**
     * Intersect with the XZ ground plane at a given Y height.
     */
    public function intersectsGroundPlane(float $groundY = 0.0): ?Vec3
    {
        $t = $this->intersectsPlane(
            new Vec3(0.0, $groundY, 0.0),
            new Vec3(0.0, 1.0, 0.0),
        );

        return $t !== null ? $this->pointAt($t) : null;
    }

    /**
     * Intersect with a sphere.
     *
     * @return float|null Distance to nearest hit, or null if no hit.
     */
    public function intersectsSphere(Vec3 $center, float $radius): ?float
    {
        $oc = $this->origin->sub($center);
        $b = $oc->dot($this->direction);
        $c = $oc->dot($oc) - $radius * $radius;
        $discriminant = $b * $b - $c;

        if ($discriminant < 0.0) {
            return null;
        }

        $sqrtD = sqrt($discriminant);
        $t = -$b - $sqrtD;
        if ($t < 0.0) {
            $t = -$b + $sqrtD;
        }

        return $t >= 0.0 ? $t : null;
    }
}
