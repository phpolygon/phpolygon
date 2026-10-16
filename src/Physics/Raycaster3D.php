<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Ray;
use PHPolygon\Math\Vec3;

/**
 * Casts rays against 3D entities with BoxCollider3D components.
 *
 * Returns the nearest hit or all hits sorted by distance.
 *
 * {@see segmentBlocked()} is a separate, cheaper occlusion query: it answers
 * "is anything solid between these two points?" from the collision data that
 * {@see \PHPolygon\System\Physics3DSystem} already caches on the colliders.
 */
class Raycaster3D
{
    /**
     * Is the segment from → to blocked by a solid collider?
     *
     * Checks every non-trigger {@see MeshCollider3D} (its world-space BVH) and
     * {@see BoxCollider3D} (its cached world AABB, or its world BVH when the box
     * is rotated). It only READS what {@see \PHPolygon\System\Physics3DSystem}
     * stores on the colliders while it ticks — nothing is transformed or rebuilt
     * here, so the query is cheap enough to run every frame. The flip side: a
     * collider the physics system has not processed yet (no cached BVH/AABB, e.g.
     * spawned this frame, or a world without a Physics3DSystem) does not block.
     *
     * Rules:
     * - Triggers never block.
     * - A box that already contains `$from` does not block (a large volume around
     *   the viewer would otherwise hide everything). Tested against the box's
     *   world AABB, which for a rotated box is its conservative bound.
     * - Touching counts: a segment ending exactly on a surface is blocked.
     * - Heightmap colliders are not considered.
     */
    public function segmentBlocked(World $world, Vec3 $from, Vec3 $to): bool
    {
        $min = new Vec3(min($from->x, $to->x), min($from->y, $to->y), min($from->z, $to->z));
        $max = new Vec3(max($from->x, $to->x), max($from->y, $to->y), max($from->z, $to->z));

        /** @var array<int, MeshCollider3D> $meshPool */
        $meshPool = $world->componentPool(MeshCollider3D::class);
        foreach ($meshPool as $collider) {
            if ($collider->isTrigger || $collider->bvh === null) {
                continue;
            }
            foreach ($collider->bvh->query($min, $max) as $triangle) {
                if (CollisionMath::segmentIntersectsTriangle($from, $to, $triangle)) {
                    return true;
                }
            }
        }

        /** @var array<int, BoxCollider3D> $boxPool */
        $boxPool = $world->componentPool(BoxCollider3D::class);
        foreach ($boxPool as $box) {
            $aabb = $box->cachedWorldAabb;
            if ($box->isTrigger || $aabb === null) {
                continue;
            }
            $bMin = $aabb['min'];
            $bMax = $aabb['max'];
            if ($max->x < $bMin->x || $min->x > $bMax->x
                || $max->y < $bMin->y || $min->y > $bMax->y
                || $max->z < $bMin->z || $min->z > $bMax->z) {
                continue;
            }
            if ($from->x >= $bMin->x && $from->x <= $bMax->x
                && $from->y >= $bMin->y && $from->y <= $bMax->y
                && $from->z >= $bMin->z && $from->z <= $bMax->z) {
                continue;
            }
            if ($box->cachedRotated && $box->bvh !== null) {
                foreach ($box->bvh->query($min, $max) as $triangle) {
                    if (CollisionMath::segmentIntersectsTriangle($from, $to, $triangle)) {
                        return true;
                    }
                }
                continue;
            }
            if (CollisionMath::segmentIntersectsAabb($from, $to, $bMin, $bMax)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cast a ray and return the nearest hit.
     */
    public function raycast(World $world, Ray $ray, float $maxDistance = 1000.0): ?RaycastHit3D
    {
        $hits = $this->raycastAll($world, $ray, $maxDistance);
        return $hits[0] ?? null;
    }

    /**
     * Cast a ray and return all hits sorted by distance (nearest first).
     *
     * @return RaycastHit3D[]
     */
    public function raycastAll(World $world, Ray $ray, float $maxDistance = 1000.0): array
    {
        $hits = [];

        foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
            $collider = $entity->get(BoxCollider3D::class);
            $transform = $entity->get(Transform3D::class);

            $pos = $transform->getWorldPosition();
            $halfSize = new Vec3(
                $collider->size->x * 0.5 * $transform->scale->x,
                $collider->size->y * 0.5 * $transform->scale->y,
                $collider->size->z * 0.5 * $transform->scale->z,
            );

            $min = new Vec3(
                $pos->x + $collider->offset->x - $halfSize->x,
                $pos->y + $collider->offset->y - $halfSize->y,
                $pos->z + $collider->offset->z - $halfSize->z,
            );
            $max = new Vec3(
                $pos->x + $collider->offset->x + $halfSize->x,
                $pos->y + $collider->offset->y + $halfSize->y,
                $pos->z + $collider->offset->z + $halfSize->z,
            );

            $t = $ray->intersectsAABB($min, $max);
            if ($t !== null && $t <= $maxDistance) {
                $hitPoint = $ray->pointAt($t);
                $normal = $this->computeAABBNormal($hitPoint, $min, $max);
                $hits[] = new RaycastHit3D($entity->id, $hitPoint, $normal, $t);
            }
        }

        usort($hits, static fn(RaycastHit3D $a, RaycastHit3D $b): int => $a->distance <=> $b->distance);

        return $hits;
    }

    /**
     * Determine which face of the AABB was hit based on hit point proximity.
     */
    private function computeAABBNormal(Vec3 $point, Vec3 $min, Vec3 $max): Vec3
    {
        $epsilon = 1e-4;

        if (abs($point->x - $min->x) < $epsilon) return new Vec3(-1.0, 0.0, 0.0);
        if (abs($point->x - $max->x) < $epsilon) return new Vec3(1.0, 0.0, 0.0);
        if (abs($point->y - $min->y) < $epsilon) return new Vec3(0.0, -1.0, 0.0);
        if (abs($point->y - $max->y) < $epsilon) return new Vec3(0.0, 1.0, 0.0);
        if (abs($point->z - $min->z) < $epsilon) return new Vec3(0.0, 0.0, -1.0);
        if (abs($point->z - $max->z) < $epsilon) return new Vec3(0.0, 0.0, 1.0);

        return new Vec3(0.0, 1.0, 0.0);
    }
}
