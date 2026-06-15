<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Sdf;

use PHPolygon\Math\Vec3;

/**
 * Sphere, mirror of {@see \PHPolygon\Geometry\SphereMesh}.
 *
 * Exact signed distance: length(p - c) - r.
 */
final readonly class SphereSdf implements SdfPrimitive
{
    public float $radius;
    public Vec3 $center;

    public function __construct(float $radius, Vec3 $center = new Vec3())
    {
        $this->radius = abs($radius);
        $this->center = $center;
    }

    public function distance(Vec3 $p): float
    {
        return $p->sub($this->center)->length() - $this->radius;
    }

    /** @return array{0: Vec3, 1: Vec3} */
    public function bounds(): array
    {
        $r = new Vec3($this->radius, $this->radius, $this->radius);
        return [$this->center->sub($r), $this->center->add($r)];
    }
}
