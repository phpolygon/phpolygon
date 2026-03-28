<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

class Physics3DSystem extends AbstractSystem
{
    private Vec3 $gravity;
    private float $groundPlaneY;

    public function __construct(?Vec3 $gravity = null, float $groundPlaneY = 0.0)
    {
        $this->gravity = $gravity ?? new Vec3(0.0, -9.81, 0.0);
        $this->groundPlaneY = $groundPlaneY;
    }

    public function setGravity(Vec3 $gravity): void
    {
        $this->gravity = $gravity;
    }

    public function getGravity(): Vec3
    {
        return $this->gravity;
    }

    public function update(World $world, float $dt): void
    {
        // Collect all static colliders once per frame
        $staticColliders = $this->collectStaticColliders($world);

        foreach ($world->query(CharacterController3D::class, Transform3D::class) as $entity) {
            $controller = $entity->get(CharacterController3D::class);
            $transform = $entity->get(Transform3D::class);

            // Apply gravity
            if (!$controller->isGrounded) {
                $controller->velocity = $controller->velocity->add($this->gravity->mul($dt));
            }

            // Integrate velocity
            $newPos = $transform->position->add($controller->velocity->mul($dt));

            // Build character capsule AABB
            $halfHeight = $controller->height / 2.0;
            $radius = $controller->radius;
            $charMin = new Vec3(
                $newPos->x - $radius,
                $newPos->y - $halfHeight,
                $newPos->z - $radius,
            );
            $charMax = new Vec3(
                $newPos->x + $radius,
                $newPos->y + $halfHeight,
                $newPos->z + $radius,
            );

            // Ground detection: floor at configurable Y
            $controller->isGrounded = false;
            if ($charMin->y <= $this->groundPlaneY) {
                $newPos = new Vec3($newPos->x, $this->groundPlaneY + $halfHeight, $newPos->z);
                $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                $controller->isGrounded = true;
                // Recompute AABB after ground snap
                $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
            }

            // Resolve AABB collisions against static colliders
            foreach ($staticColliders as $collider) {
                if ($collider['entityId'] === $entity->id) {
                    continue;
                }

                $resolution = self::resolveAABB($charMin, $charMax, $collider['min'], $collider['max']);
                if ($resolution !== null) {
                    $newPos = $newPos->add($resolution);

                    // Zero velocity along collision normal
                    if (abs($resolution->x) > 0.0001) {
                        $controller->velocity = new Vec3(0.0, $controller->velocity->y, $controller->velocity->z);
                    }
                    if (abs($resolution->y) > 0.0001) {
                        $controller->velocity = new Vec3($controller->velocity->x, 0.0, $controller->velocity->z);
                        if ($resolution->y > 0) {
                            $controller->isGrounded = true;
                        }
                    }
                    if (abs($resolution->z) > 0.0001) {
                        $controller->velocity = new Vec3($controller->velocity->x, $controller->velocity->y, 0.0);
                    }

                    // Update AABB for next collision test
                    $charMin = new Vec3($newPos->x - $radius, $newPos->y - $halfHeight, $newPos->z - $radius);
                    $charMax = new Vec3($newPos->x + $radius, $newPos->y + $halfHeight, $newPos->z + $radius);
                }
            }

            $transform->position = $newPos;
            $transform->worldMatrix = $transform->getLocalMatrix();
        }
    }

    /**
     * Collect all static BoxCollider3D world AABBs.
     *
     * @return list<array{entityId: int, min: Vec3, max: Vec3}>
     */
    private function collectStaticColliders(World $world): array
    {
        $colliders = [];
        foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
            $collider = $entity->get(BoxCollider3D::class);
            if (!$collider->isStatic) {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $pos = $transform->getWorldPosition();

            // Apply scale to collider size
            $scaledSize = new Vec3(
                $collider->size->x * $transform->scale->x,
                $collider->size->y * $transform->scale->y,
                $collider->size->z * $transform->scale->z,
            );

            $center = $pos->add($collider->offset);
            $halfSize = new Vec3($scaledSize->x * 0.5, $scaledSize->y * 0.5, $scaledSize->z * 0.5);

            $colliders[] = [
                'entityId' => $entity->id,
                'min' => $center->sub($halfSize),
                'max' => $center->add($halfSize),
            ];
        }
        return $colliders;
    }

    /**
     * Test AABB overlap and return the minimum penetration resolution vector.
     * Returns null if no overlap.
     */
    public static function resolveAABB(Vec3 $aMin, Vec3 $aMax, Vec3 $bMin, Vec3 $bMax): ?Vec3
    {
        $overlapX = min($aMax->x, $bMax->x) - max($aMin->x, $bMin->x);
        $overlapY = min($aMax->y, $bMax->y) - max($aMin->y, $bMin->y);
        $overlapZ = min($aMax->z, $bMax->z) - max($aMin->z, $bMin->z);

        if ($overlapX <= 0 || $overlapY <= 0 || $overlapZ <= 0) {
            return null;
        }

        // Push out along axis of minimum penetration
        $centerAx = ($aMin->x + $aMax->x) * 0.5;
        $centerBx = ($bMin->x + $bMax->x) * 0.5;
        $centerAy = ($aMin->y + $aMax->y) * 0.5;
        $centerBy = ($bMin->y + $bMax->y) * 0.5;
        $centerAz = ($aMin->z + $aMax->z) * 0.5;
        $centerBz = ($bMin->z + $bMax->z) * 0.5;

        if ($overlapX <= $overlapY && $overlapX <= $overlapZ) {
            $sign = $centerAx < $centerBx ? -1.0 : 1.0;
            return new Vec3($sign * $overlapX, 0.0, 0.0);
        }
        if ($overlapY <= $overlapX && $overlapY <= $overlapZ) {
            $sign = $centerAy < $centerBy ? -1.0 : 1.0;
            return new Vec3(0.0, $sign * $overlapY, 0.0);
        }
        $sign = $centerAz < $centerBz ? -1.0 : 1.0;
        return new Vec3(0.0, 0.0, $sign * $overlapZ);
    }
}
