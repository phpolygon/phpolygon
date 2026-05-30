<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Projectile;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

/**
 * Integrates every {@see Projectile}'s position by its per-tick velocity and
 * despawns it once it has lived {@see Projectile::$lifetime} ticks. Hit
 * resolution is {@see DamageSystem}'s job; this system only moves and expires.
 */
class ProjectileSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        /** @var list<int> $expired */
        $expired = [];

        foreach ($world->query(Projectile::class, Transform3D::class) as $entity) {
            $proj = $entity->get(Projectile::class);
            $tf = $entity->get(Transform3D::class);

            $p = $tf->position;
            $v = $proj->velocity;
            $tf->position = new Vec3($p->x + $v->x, $p->y + $v->y, $p->z + $v->z);

            $proj->age++;
            if ($proj->age >= $proj->lifetime) {
                $expired[] = $entity->id;
            }
        }

        foreach ($expired as $id) {
            $world->destroyEntity($id);
        }
    }
}
