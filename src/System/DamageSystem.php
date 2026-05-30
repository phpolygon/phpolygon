<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Health;
use PHPolygon\Component\Projectile;
use PHPolygon\Component\ShooterGameState;
use PHPolygon\Component\ShooterStatus;
use PHPolygon\Component\Team;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Resolves all damage in the shooter layer:
 *   - {@see Projectile} ↔ opposing-{@see Team} {@see Health} overlaps,
 *   - enemy body-contact (ramming) against the player,
 *   - i-frame countdown and death cleanup.
 *
 * {@see self::damage()} is the single entry point for applying a hit (also used
 * by {@see WeaponSystem} hitscan): it honours i-frames, awards score on an enemy
 * kill, and costs the player a life. Entities are never destroyed mid-iteration —
 * dead enemies and spent projectiles are swept after the queries.
 */
class DamageSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        $state = $this->findState($world);

        // i-frame countdown for every combatant.
        foreach ($world->query(Health::class) as $entity) {
            $h = $entity->get(Health::class);
            if ($h->invuln > 0) {
                $h->invuln--;
            }
        }

        /** @var list<int> $despawn spent projectiles */
        $despawn = [];

        // --- projectiles vs health ---
        foreach ($world->query(Projectile::class, Transform3D::class) as $pEntity) {
            $proj = $pEntity->get(Projectile::class);
            $pPos = $pEntity->get(Transform3D::class)->position;

            foreach ($world->query(Health::class, Transform3D::class) as $hEntity) {
                $h = $hEntity->get(Health::class);
                if ($h->dead || $h->invuln > 0 || $h->team === $proj->team) {
                    continue;
                }
                $hPos = $hEntity->get(Transform3D::class)->position;
                $r = $proj->hitRadius + $h->contactRadius;
                $dx = $pPos->x - $hPos->x;
                $dy = $pPos->y - $hPos->y;
                $dz = $pPos->z - $hPos->z;
                if ($dx * $dx + $dy * $dy + $dz * $dz <= $r * $r) {
                    self::damage($world, $hEntity->id, $h, $proj->damage, $state);
                    $despawn[] = $pEntity->id;
                    break; // one projectile, one hit
                }
            }
        }

        // --- enemy body-contact (ramming) vs player ---
        /** @var list<array{id:int, h:Health, x:float, y:float, z:float}> $players */
        $players = [];
        foreach ($world->query(Health::class, Transform3D::class) as $entity) {
            $h = $entity->get(Health::class);
            if ($h->team === Team::Player && !$h->dead) {
                $p = $entity->get(Transform3D::class)->position;
                $players[] = ['id' => $entity->id, 'h' => $h, 'x' => $p->x, 'y' => $p->y, 'z' => $p->z];
            }
        }
        if ($players !== []) {
            foreach ($world->query(Health::class, Transform3D::class) as $entity) {
                $h = $entity->get(Health::class);
                if ($h->dead || $h->team !== Team::Enemy || $h->contactDamage <= 0.0) {
                    continue;
                }
                $e = $entity->get(Transform3D::class)->position;
                foreach ($players as $p) {
                    $r = $h->contactRadius + $p['h']->contactRadius;
                    $dx = $e->x - $p['x'];
                    $dy = $e->y - $p['y'];
                    $dz = $e->z - $p['z'];
                    if ($dx * $dx + $dy * $dy + $dz * $dz <= $r * $r) {
                        self::damage($world, $p['id'], $p['h'], $h->contactDamage, $state);
                        $h->dead = true; // the rammer is consumed
                        break;
                    }
                }
            }
        }

        // --- sweep: spent projectiles + dead despawnable non-players ---
        foreach (array_unique($despawn) as $id) {
            $world->destroyEntity($id);
        }
        /** @var list<int> $deadIds */
        $deadIds = [];
        foreach ($world->query(Health::class) as $entity) {
            $h = $entity->get(Health::class);
            if ($h->dead && $h->despawnOnDeath && $h->team !== Team::Player) {
                $deadIds[] = $entity->id;
            }
        }
        foreach ($deadIds as $id) {
            $world->destroyEntity($id);
        }
    }

    /**
     * Apply $dmg to $h, the single chokepoint for every damage source. Honours
     * i-frames, marks enemies dead (the caller's sweep despawns them) and awards
     * {@see Health::$scoreOnDeath}; for the player it costs a life and either
     * respawns (refill + i-frames) or ends the run at zero lives. Does not
     * destroy entities, so it is safe to call mid-iteration.
     */
    public static function damage(World $world, int $entityId, Health $h, float $dmg, ?ShooterGameState $state): void
    {
        if ($h->dead || $h->invuln > 0 || $dmg <= 0.0) {
            return;
        }
        $h->hp -= (int) ceil($dmg);
        $h->invuln = $h->invulnFrames;
        if ($h->hp > 0) {
            return;
        }

        if ($h->team === Team::Player) {
            if ($state !== null) {
                $state->lives--;
                $state->score = max(0, $state->score - $state->deathPenalty);
                if ($state->lives <= 0) {
                    $state->status = ShooterStatus::Lost;
                    $h->dead = true;
                    return;
                }
            }
            // Survive: refill and grant a respawn-grace window.
            $h->hp = $h->maxHp;
            $h->invuln = max($h->invulnFrames, 60);
            return;
        }

        // Enemy (or neutral) death — score the kill; the sweep despawns it.
        $h->dead = true;
        if ($state !== null) {
            $state->score += $h->scoreOnDeath;
        }
    }

    private function findState(World $world): ?ShooterGameState
    {
        foreach ($world->query(ShooterGameState::class) as $entity) {
            return $entity->get(ShooterGameState::class);
        }
        return null;
    }
}
