<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Health;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Mover;
use PHPolygon\Component\ShooterGameState;
use PHPolygon\Component\ShooterStatus;
use PHPolygon\Component\Spawner;
use PHPolygon\Component\Team;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

/**
 * Drives every {@see Spawner}: once its interval elapses it creates an enemy
 * from the spawner's template (a {@see MeshRenderer} + {@see Health} (Enemy) +
 * {@see Mover}) at a random point in the spawn volume, respecting
 * {@see Spawner::$maxAlive} and {@see Spawner::$totalToSpawn}. Spawning halts
 * while the {@see ShooterGameState} is not {@see ShooterStatus::Playing}.
 */
class SpawnerSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        $state = $this->findState($world);
        if ($state !== null && $state->status !== ShooterStatus::Playing) {
            return;
        }

        $aliveEnemies = $this->countEnemies($world);

        foreach ($world->query(Spawner::class, Transform3D::class) as $entity) {
            $sp = $entity->get(Spawner::class);

            if ($sp->timer > 0) {
                $sp->timer--;
                continue;
            }
            $sp->timer = $sp->interval;

            if ($sp->totalToSpawn >= 0 && $sp->spawnedCount >= $sp->totalToSpawn) {
                continue;
            }
            if ($aliveEnemies >= $sp->maxAlive) {
                continue;
            }

            $this->spawnEnemy($world, $sp);
            $sp->spawnedCount++;
            $aliveEnemies++;
        }
    }

    private function spawnEnemy(World $world, Spawner $sp): void
    {
        $pos = new Vec3(
            $this->randRange($sp->areaMin->x, $sp->areaMax->x),
            $this->randRange($sp->areaMin->y, $sp->areaMax->y),
            $this->randRange($sp->areaMin->z, $sp->areaMax->z),
        );
        $s = $sp->enemyScale;

        $enemy = $world->createEntity();
        $enemy->attach(new Transform3D(position: $pos, scale: new Vec3($s, $s, $s)));
        $enemy->attach(new MeshRenderer(meshId: $sp->enemyMeshId, materialId: $sp->enemyMaterialId));
        $enemy->attach(new Health(
            maxHp: $sp->enemyHp,
            team: Team::Enemy,
            invulnFrames: 0,
            scoreOnDeath: $sp->enemyScore,
            despawnOnDeath: true,
            contactRadius: max(0.3, $s),
            contactDamage: $sp->enemyContactDamage,
        ));
        $enemy->attach(new Mover(
            velocity: new Vec3($sp->enemyVelocity->x, $sp->enemyVelocity->y, $sp->enemyVelocity->z),
            homingRate: $sp->enemyHomingRate,
            homingTeam: Team::Player,
            despawnDistance: 600.0,
        ));
    }

    private function countEnemies(World $world): int
    {
        $n = 0;
        foreach ($world->query(Health::class) as $entity) {
            $h = $entity->get(Health::class);
            if ($h->team === Team::Enemy && !$h->dead) {
                $n++;
            }
        }
        return $n;
    }

    /** Uniform random in [$a, $b]; returns $a when the range is degenerate. */
    private function randRange(float $a, float $b): float
    {
        if ($b <= $a) {
            return $a;
        }
        return $a + (mt_rand() / mt_getrandmax()) * ($b - $a);
    }

    private function findState(World $world): ?ShooterGameState
    {
        foreach ($world->query(ShooterGameState::class) as $entity) {
            return $entity->get(ShooterGameState::class);
        }
        return null;
    }
}
