<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/** How a {@see Weapon} delivers damage. */
enum WeaponMode: string
{
    /** Spawns a travelling {@see Projectile} entity. */
    case Projectile = 'projectile';
    /** Instant ray hit against the nearest opposing {@see Health}. */
    case Hitscan = 'hitscan';
}

/**
 * A gun attached to a combatant. {@see \PHPolygon\System\WeaponSystem} fires it
 * when {@see $firing} (player intent, set by {@see \PHPolygon\System\ShooterControllerSystem})
 * or {@see $autoFire} (enemies) and the {@see $cooldown} has elapsed, aiming
 * along {@see $aim}. All cadence values are in fixed-60 Hz ticks.
 */
#[Serializable]
#[Category('Gameplay')]
class Weapon extends AbstractComponent
{
    #[Property]
    public WeaponMode $mode;

    /** Ticks between shots. */
    #[Property]
    public int $fireRate;

    /** Damage per shot. */
    #[Property]
    public float $damage;

    /** Projectile speed, units per tick (projectile mode). */
    #[Property]
    public float $projectileSpeed;

    /** Projectile lifetime in ticks (projectile mode). */
    #[Property]
    public int $projectileLifetime;

    /** Maximum hit distance (hitscan mode). */
    #[Property]
    public float $range;

    /** Spawn offset from the owner origin, in the owner's local space. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $muzzleOffset;

    /** Registered mesh id for the spawned projectile (projectile mode). */
    #[Property]
    public string $projectileMeshId;

    /** Registered material id for the spawned projectile (projectile mode). */
    #[Property]
    public string $projectileMaterialId;

    /** Uniform scale applied to the spawned projectile. */
    #[Property]
    public float $projectileScale;

    /** Fire on every available cooldown without an explicit intent (enemies). */
    #[Property]
    public bool $autoFire;

    // --- runtime state ---

    /** Remaining cooldown ticks until the next shot is allowed. */
    #[Hidden]
    public int $cooldown = 0;

    /** Per-tick fire intent (player); consumed by WeaponSystem each tick. */
    #[Hidden]
    public bool $firing = false;

    /** Unit aim direction in world space, set by the controller / enemy AI. */
    #[Hidden]
    public Vec3 $aim;

    public function __construct(
        WeaponMode $mode = WeaponMode::Projectile,
        int $fireRate = 12,
        float $damage = 1.0,
        float $projectileSpeed = 1.2,
        int $projectileLifetime = 120,
        float $range = 100.0,
        ?Vec3 $muzzleOffset = null,
        string $projectileMeshId = 'projectile',
        string $projectileMaterialId = 'projectile',
        float $projectileScale = 1.0,
        bool $autoFire = false,
    ) {
        $this->mode = $mode;
        $this->fireRate = max(1, $fireRate);
        $this->damage = $damage;
        $this->projectileSpeed = $projectileSpeed;
        $this->projectileLifetime = max(1, $projectileLifetime);
        $this->range = $range;
        $this->muzzleOffset = $muzzleOffset ?? new Vec3(0.0, 0.0, -1.0);
        $this->projectileMeshId = $projectileMeshId;
        $this->projectileMaterialId = $projectileMaterialId;
        $this->projectileScale = $projectileScale;
        $this->autoFire = $autoFire;
        $this->aim = new Vec3(0.0, 0.0, -1.0);
    }
}
