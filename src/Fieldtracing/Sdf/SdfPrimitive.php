<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Sdf;

use PHPolygon\Math\Vec3;

/**
 * A signed distance function over 3D space.
 *
 * The contract mirrors the analytic SDF primitives the engine already builds
 * geometry from (BoxMesh / SphereMesh / CylinderMesh / PlaneMesh): one exact
 * closed-form distance per primitive, composable via {@see SdfComposite}.
 *
 * Sign convention (standard sphere-tracing convention, matches the GLSL side):
 *   - negative inside the surface,
 *   - zero on the surface,
 *   - positive outside,
 *   - magnitude is the Euclidean distance to the nearest surface point.
 *
 * Fieldtracing marches these fields on the GPU (Tier A/B). On the PHP side they
 * are evaluated headlessly for baking ({@see \PHPolygon\Fieldtracing\Bake\SdfVolumeBaker})
 * and for GPU-free unit tests against known distance values.
 */
interface SdfPrimitive
{
    /** Signed distance from world-space point $p to this field's surface. */
    public function distance(Vec3 $p): float;

    /**
     * Axis-aligned bounding box of the surface as [Vec3 $min, Vec3 $max], or
     * null when the field is unbounded (e.g. an infinite plane). Used by the
     * baker to size the volume and by composites to union child extents.
     *
     * @return array{0: Vec3, 1: Vec3}|null
     */
    public function bounds(): ?array;
}
