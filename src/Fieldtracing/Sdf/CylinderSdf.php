<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Sdf;

use PHPolygon\Math\Vec3;

/**
 * Capped cylinder aligned to the Y axis, mirror of
 * {@see \PHPolygon\Geometry\CylinderMesh}.
 *
 * Exact signed distance (Quilez capped cylinder): with radial distance
 *   d = (|p.xz - c.xz| - r, |p.y - c.y| - halfHeight)
 *   dist = min(max(d.x, d.y), 0) + length(max(d, 0))
 */
final readonly class CylinderSdf implements SdfPrimitive
{
    public float $radius;
    public float $halfHeight;
    public Vec3 $center;

    /**
     * @param float $radius Cylinder radius (same as CylinderMesh::generate()).
     * @param float $height Full height along Y; half-height is used internally.
     */
    public function __construct(float $radius, float $height, Vec3 $center = new Vec3())
    {
        $this->radius = abs($radius);
        $this->halfHeight = abs($height) * 0.5;
        $this->center = $center;
    }

    public function distance(Vec3 $p): float
    {
        $dx = $p->x - $this->center->x;
        $dz = $p->z - $this->center->z;
        $radial = sqrt($dx * $dx + $dz * $dz) - $this->radius;
        $axial = abs($p->y - $this->center->y) - $this->halfHeight;

        $outside = sqrt(max($radial, 0.0) ** 2 + max($axial, 0.0) ** 2);
        $inside = min(max($radial, $axial), 0.0);

        return $outside + $inside;
    }

    /** @return array{0: Vec3, 1: Vec3} */
    public function bounds(): array
    {
        $ext = new Vec3($this->radius, $this->halfHeight, $this->radius);
        return [$this->center->sub($ext), $this->center->add($ext)];
    }
}
