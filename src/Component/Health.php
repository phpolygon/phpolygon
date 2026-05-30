<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/** Which side an entity fights for — used to filter who damages whom. */
enum Team: string
{
    case Player = 'player';
    case Enemy = 'enemy';
    case Neutral = 'neutral';
}

/**
 * Hit points + faction for any combatant (player or enemy). The
 * {@see \PHPolygon\System\DamageSystem} subtracts {@see Projectile} and contact
 * damage from {@see $hp}, awards {@see $scoreOnDeath} to the
 * {@see ShooterGameState} when an enemy dies, and despawns the entity (or, for
 * the player, costs a life) at zero.
 *
 * Frame-based units (fixed 60 Hz tick), matching the rest of the gameplay layer.
 */
#[Serializable]
#[Category('Gameplay')]
class Health extends AbstractComponent
{
    /** Maximum (and starting) hit points. */
    #[Property]
    public int $maxHp;

    /** Faction — projectiles only damage the opposing team. */
    #[Property]
    public Team $team;

    /** Invulnerability frames granted after taking damage (0 = none). */
    #[Property]
    public int $invulnFrames;

    /** Score awarded to the opposing team's state when this entity dies. */
    #[Property]
    public int $scoreOnDeath;

    /** Remove the entity (and its child meshes) when hp reaches 0. */
    #[Property]
    public bool $despawnOnDeath;

    /** Horizontal radius used for body-contact (ramming) tests. */
    #[Property]
    public float $contactRadius;

    /** Damage dealt to the opposing team on body contact (0 = harmless body). */
    #[Property]
    public float $contactDamage;

    // --- runtime state ---

    #[Hidden]
    public int $hp;

    #[Hidden]
    public int $invuln = 0;

    #[Hidden]
    public bool $dead = false;

    public function __construct(
        int $maxHp = 3,
        Team $team = Team::Enemy,
        int $invulnFrames = 0,
        int $scoreOnDeath = 100,
        bool $despawnOnDeath = true,
        float $contactRadius = 1.0,
        float $contactDamage = 0.0,
    ) {
        $this->maxHp = max(1, $maxHp);
        $this->team = $team;
        $this->invulnFrames = max(0, $invulnFrames);
        $this->scoreOnDeath = $scoreOnDeath;
        $this->despawnOnDeath = $despawnOnDeath;
        $this->contactRadius = $contactRadius;
        $this->contactDamage = $contactDamage;
        $this->hp = $this->maxHp;
    }
}
