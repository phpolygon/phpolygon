<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Sdf;

use PHPolygon\Math\Vec3;

/**
 * Axis-aligned box, mirror of {@see \PHPolygon\Geometry\BoxMesh}.
 *
 * Exact signed distance (Quilez): given half-extents h and centre c,
 *   q = abs(p - c) - h
 *   d = length(max(q, 0)) + min(max(q.x, q.y, q.z), 0)
 */
final readonly class BoxSdf implements SdfPrimitive
{
    public Vec3 $center;
    public Vec3 $halfExtents;

    /**
     * @param Vec3 $size   Full width/height/depth (same convention as BoxMesh::generate()).
     * @param Vec3 $center World-space centre of the box.
     */
    public function __construct(Vec3 $size, Vec3 $center = new Vec3())
    {
        $this->center = $center;
        $this->halfExtents = new Vec3(abs($size->x) * 0.5, abs($size->y) * 0.5, abs($size->z) * 0.5);
    }

    public function distance(Vec3 $p): float
    {
        $qx = abs($p->x - $this->center->x) - $this->halfExtents->x;
        $qy = abs($p->y - $this->center->y) - $this->halfExtents->y;
        $qz = abs($p->z - $this->center->z) - $this->halfExtents->z;

        $outside = sqrt(
            max($qx, 0.0) ** 2 + max($qy, 0.0) ** 2 + max($qz, 0.0) ** 2
        );
        $inside = min(max($qx, max($qy, $qz)), 0.0);

        return $outside + $inside;
    }

    /** @return array{0: Vec3, 1: Vec3} */
    public function bounds(): array
    {
        return [
            $this->center->sub($this->halfExtents),
            $this->center->add($this->halfExtents),
        ];
    }
}
