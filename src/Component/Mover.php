<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Constant-velocity travel (units per tick), with optional homing toward the
 * nearest {@see Health} of {@see $homingTeam}. Driven by
 * {@see \PHPolygon\System\MoverSystem}, which also despawns the entity once it
 * passes {@see $despawnDistance} from the world origin (0 = never). Used for
 * incoming enemies in an arcade shooter.
 */
#[Serializable]
#[Category('Gameplay')]
class Mover extends AbstractComponent
{
    /** World-space velocity, units per tick. */
    #[Property(editorHint: 'vec3')]
    public Vec3 $velocity;

    /** Steering rate toward the target team (0 = fly straight). */
    #[Property]
    public float $homingRate;

    /** Which team to home in on when {@see $homingRate} > 0. */
    #[Property]
    public Team $homingTeam;

    /** Distance from the world origin past which the entity despawns (0 = never). */
    #[Property]
    public float $despawnDistance;

    public function __construct(
        ?Vec3 $velocity = null,
        float $homingRate = 0.0,
        Team $homingTeam = Team::Player,
        float $despawnDistance = 0.0,
    ) {
        $this->velocity = $velocity ?? Vec3::zero();
        $this->homingRate = max(0.0, $homingRate);
        $this->homingTeam = $homingTeam;
        $this->despawnDistance = max(0.0, $despawnDistance);
    }
}
