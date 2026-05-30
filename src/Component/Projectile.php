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
 * A flying shot spawned by {@see \PHPolygon\System\WeaponSystem}. The
 * {@see \PHPolygon\System\ProjectileSystem} integrates its {@see $velocity}
 * (units per tick) and despawns it after {@see $lifetime} ticks; the
 * {@see \PHPolygon\System\DamageSystem} applies {@see $damage} to the first
 * opposing-{@see Team} {@see Health} it overlaps.
 */
#[Serializable]
#[Category('Gameplay')]
class Projectile extends AbstractComponent
{
    /** World-space velocity, units per tick. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $velocity;

    /** Damage dealt on hit. */
    #[Property]
    public float $damage;

    /** Lifetime in ticks before it despawns on its own. */
    #[Property]
    public int $lifetime;

    /** Owning faction — does not hit its own team. */
    #[Property]
    public Team $team;

    /** Overlap radius for the hit test. */
    #[Property]
    public float $hitRadius;

    #[Hidden]
    public int $age = 0;

    public function __construct(
        ?Vec3 $velocity = null,
        float $damage = 1.0,
        int $lifetime = 120,
        Team $team = Team::Player,
        float $hitRadius = 0.5,
    ) {
        $this->velocity = $velocity ?? new Vec3(0.0, 0.0, -1.0);
        $this->damage = $damage;
        $this->lifetime = max(1, $lifetime);
        $this->team = $team;
        $this->hitRadius = $hitRadius;
    }
}
