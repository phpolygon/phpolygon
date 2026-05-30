<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Emits enemy entities over time. {@see \PHPolygon\System\SpawnerSystem} creates
 * one enemy every {@see $interval} ticks at a random point inside the
 * [{@see $areaMin}, {@see $areaMax}] box, up to {@see $maxAlive} concurrent
 * enemies and {@see $totalToSpawn} total (-1 = endless). Each enemy gets the
 * template below: a {@see MeshRenderer}, {@see Health} (Enemy), and a
 * {@see Mover} with {@see $enemyVelocity}.
 */
#[Serializable]
#[Category('Gameplay')]
class Spawner extends AbstractComponent
{
    /** Ticks between spawns. */
    #[Property]
    public int $interval;

    /** Spawn-volume minimum corner (world space). */
    #[Property(editorHint: 'vec3')]
    public Vec3 $areaMin;

    /** Spawn-volume maximum corner (world space). */
    #[Property(editorHint: 'vec3')]
    public Vec3 $areaMax;

    /** Maximum concurrently-alive enemies from this spawner. */
    #[Property]
    public int $maxAlive;

    /** Total enemies to spawn over the run (-1 = endless). */
    #[Property]
    public int $totalToSpawn;

    // --- enemy template ---

    #[Property]
    public string $enemyMeshId;

    #[Property]
    public string $enemyMaterialId;

    #[Property]
    public float $enemyScale;

    #[Property]
    public int $enemyHp;

    /** Mover velocity given to each spawned enemy (units per tick). */
    #[Property(editorHint: 'vec3')]
    public Vec3 $enemyVelocity;

    /** Homing steer rate for spawned enemies toward the player (0 = fly straight). */
    #[Property]
    public float $enemyHomingRate;

    #[Property]
    public int $enemyScore;

    /** Body-contact damage each enemy deals to the player on collision. */
    #[Property]
    public float $enemyContactDamage;

    // --- runtime state ---

    #[Hidden]
    public int $timer = 0;

    #[Hidden]
    public int $spawnedCount = 0;

    public function __construct(
        int $interval = 90,
        ?Vec3 $areaMin = null,
        ?Vec3 $areaMax = null,
        int $maxAlive = 8,
        int $totalToSpawn = -1,
        string $enemyMeshId = 'enemy',
        string $enemyMaterialId = 'enemy',
        float $enemyScale = 1.0,
        int $enemyHp = 1,
        ?Vec3 $enemyVelocity = null,
        float $enemyHomingRate = 0.0,
        int $enemyScore = 100,
        float $enemyContactDamage = 1.0,
    ) {
        $this->interval = max(1, $interval);
        $this->areaMin = $areaMin ?? new Vec3(-5.0, 0.0, -50.0);
        $this->areaMax = $areaMax ?? new Vec3(5.0, 0.0, -50.0);
        $this->maxAlive = max(1, $maxAlive);
        $this->totalToSpawn = $totalToSpawn;
        $this->enemyMeshId = $enemyMeshId;
        $this->enemyMaterialId = $enemyMaterialId;
        $this->enemyScale = $enemyScale;
        $this->enemyHp = max(1, $enemyHp);
        $this->enemyVelocity = $enemyVelocity ?? new Vec3(0.0, 0.0, 0.4);
        $this->enemyHomingRate = max(0.0, $enemyHomingRate);
        $this->enemyScore = $enemyScore;
        $this->enemyContactDamage = $enemyContactDamage;
    }
}
