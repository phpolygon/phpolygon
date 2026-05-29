<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Patrol;
use PHPolygon\Component\PatrolAxis;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Oscillates every {@see Patrol} entity back and forth along one world axis
 * between its bounds and yaws it to face the direction of travel.
 */
class PatrolSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        foreach ($world->query(Patrol::class, Transform3D::class) as $entity) {
            $p = $entity->get(Patrol::class);
            $tf = $entity->get(Transform3D::class);
            $pos = $tf->position;

            $value = match ($p->axis) {
                PatrolAxis::X => $pos->x,
                PatrolAxis::Y => $pos->y,
                PatrolAxis::Z => $pos->z,
            };

            $value += $p->dir * $p->speed;
            if ($value > $p->max) { $value = $p->max; $p->dir = -1; }
            if ($value < $p->min) { $value = $p->min; $p->dir = 1; }

            $tf->position = match ($p->axis) {
                PatrolAxis::X => new Vec3($value, $pos->y, $pos->z),
                PatrolAxis::Y => new Vec3($pos->x, $value, $pos->z),
                PatrolAxis::Z => new Vec3($pos->x, $pos->y, $value),
            };

            // Face travel direction (yaw around Y).
            $dirVec = match ($p->axis) {
                PatrolAxis::X => [$p->dir, 0.0],
                PatrolAxis::Z => [0.0, $p->dir],
                PatrolAxis::Y => [0.0, 0.0],
            };
            if ($dirVec !== [0.0, 0.0]) {
                $yaw = atan2((float)$dirVec[0], (float)$dirVec[1]);
                $tf->rotation = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $yaw);
            }
        }
    }
}
