<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Ray;
use PHPolygon\Math\Vec3;

/**
 * Casts rays against 3D entities with BoxCollider3D components.
 *
 * Returns the nearest hit or all hits sorted by distance.
 */
class Raycaster3D
{
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
