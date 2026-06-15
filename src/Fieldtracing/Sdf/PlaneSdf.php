<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Sdf;

use PHPolygon\Math\Vec3;

/**
 * Infinite plane, mirror of {@see \PHPolygon\Geometry\PlaneMesh} (which produces
 * a finite quad; the SDF is the unbounded supporting plane).
 *
 * Signed distance: dot(p, n) - offset, where n is the unit normal and the
 * surface is the set { x : dot(x, n) = offset }. Positive on the side the
 * normal points toward.
 *
 * The field is unbounded, so {@see bounds()} returns null; callers that bake a
 * plane must supply their own finite volume extent.
 */
final readonly class PlaneSdf implements SdfPrimitive
{
    public Vec3 $normal;
    public float $offset;

    /**
     * @param Vec3  $normal Plane normal (normalised internally).
     * @param float $offset Signed distance of the plane from the origin along
     *                      the normal (default 0 = plane through origin).
     */
    public function __construct(Vec3 $normal = new Vec3(0.0, 1.0, 0.0), float $offset = 0.0)
    {
        $this->normal = $normal->normalize();
        $this->offset = $offset;
    }

    public function distance(Vec3 $p): float
    {
        return $p->dot($this->normal) - $this->offset;
    }

    public function bounds(): ?array
    {
        return null; // infinite
    }
}
