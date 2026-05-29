<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Collectible;
use PHPolygon\Component\PlatformerController;
use PHPolygon\Component\PlatformerGameState;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

/**
 * Picks up {@see Collectible} entities when a {@see PlatformerController} comes
 * within range: adds score + coins to the {@see PlatformerGameState} and
 * destroys the collected entity (removing its mesh from the scene).
 */
class CollectibleSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        /** @var list<Vec3> $players */
        $players = [];
        foreach ($world->query(PlatformerController::class, Transform3D::class) as $entity) {
            // Read world position so a parented player (e.g. riding a moving
            // platform) is compared in the same space as the collectibles below.
            $players[] = $entity->get(Transform3D::class)->getWorldPosition();
        }
        if ($players === []) {
            return;
        }

        $state = $this->findState($world);

        /** @var list<int> $consumed */
        $consumed = [];
        foreach ($world->query(Collectible::class, Transform3D::class) as $entity) {
            $col = $entity->get(Collectible::class);
            if ($col->collected) {
                continue;
            }
            $c = $entity->get(Transform3D::class)->getWorldPosition();
            $r2 = $col->radius * $col->radius;
            foreach ($players as $p) {
                $dx = $p->x - $c->x;
                $dy = ($p->y + $col->playerYOffset) - $c->y;
                $dz = $p->z - $c->z;
                if ($dx * $dx + $dy * $dy + $dz * $dz < $r2) {
                    $col->collected = true;
                    if ($state !== null) {
                        $state->coins += $col->coinValue;
                        $state->score += $col->score;
                    }
                    $consumed[] = $entity->id;
                    break;
                }
            }
        }

        foreach ($consumed as $id) {
            $world->destroyEntity($id);
        }
    }

    private function findState(World $world): ?PlatformerGameState
    {
        foreach ($world->query(PlatformerGameState::class) as $entity) {
            return $entity->get(PlatformerGameState::class);
        }
        return null;
    }
}
