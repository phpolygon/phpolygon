<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\FollowCamera;
use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Eases every {@see FollowCamera} entity's transform toward a framing derived
 * from its target entity's world position, then orients it to look at the
 * computed look-point. {@see Camera3DSystem} subsequently builds the view from
 * that transform.
 *
 * Runs in the update phase (fixed timestep) so the lerp is frame-rate
 * independent, after {@see PlatformerControllerSystem} has moved the target.
 */
class FollowCameraSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        foreach ($world->query(FollowCamera::class, Camera3DComponent::class, Transform3D::class) as $entity) {
            $fc = $entity->get(FollowCamera::class);
            $tf = $entity->get(Transform3D::class);

            $target = $this->resolveTarget($world, $fc->targetName);
            if ($target === null) {
                continue;
            }

            $eye = new Vec3(
                $target->x * $fc->positionScale->x + $fc->positionOffset->x,
                $target->y * $fc->positionScale->y + $fc->positionOffset->y,
                $target->z * $fc->positionScale->z + $fc->positionOffset->z,
            );
            $look = new Vec3(
                $target->x * $fc->lookScale->x + $fc->lookOffset->x,
                $target->y * $fc->lookScale->y + $fc->lookOffset->y,
                $target->z * $fc->lookScale->z + $fc->lookOffset->z,
            );

            $eyePos = $fc->initialised
                ? $tf->position->lerp($eye, max(0.0, min(1.0, $fc->lerpFactor)))
                : $eye;
            $fc->initialised = true;

            $tf->position = $eyePos;
            // Camera world-rotation = inverse of the look-at view matrix.
            $tf->rotation = Quaternion::fromRotationMatrix(
                Mat4::lookAt($eyePos, $look, new Vec3(0.0, 1.0, 0.0))->inverse(),
            );
        }
    }

    private function resolveTarget(World $world, string $name): ?Vec3
    {
        if ($name === '') {
            return null;
        }
        foreach ($world->query(NameTag::class, Transform3D::class) as $entity) {
            if ($entity->get(NameTag::class)->name === $name) {
                // Read the live local position: the target is expected to be a
                // top-level entity (position == world), and this avoids a
                // one-frame lag on the cached world matrix when this system
                // runs before Transform3DSystem.
                return $entity->get(Transform3D::class)->position;
            }
        }
        return null;
    }
}
