<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * An enemy that is defeated by being jumped on from above and damages the
 * player on side contact — the Goomba rule. Resolved against the
 * {@see PlatformerController} by {@see \PHPolygon\System\StompSystem}.
 *
 * Heights are measured from the entity origin (its feet): the player stomps
 * when descending and their feet are above {@see $stompHeight}; otherwise a
 * contact within {@see $contactRadius} hurts the player.
 */
#[Serializable]
#[Category('Gameplay')]
class Stompable extends AbstractComponent
{
    /** Horizontal radius within which the player interacts with the enemy. */
    #[Property]
    public float $contactRadius;

    /** Full collision height above the entity origin. */
    #[Property]
    public float $bodyHeight;

    /** Feet must be above (origin + stompHeight) for a hit to count as a stomp. */
    #[Property]
    public float $stompHeight;

    /** Upward velocity given to the player after a successful stomp. */
    #[Property]
    public float $bounceVelocity;

    /** Score awarded for a stomp. */
    #[Property]
    public int $score;

    /** Vertical squash scale applied while the corpse lingers. */
    #[Property]
    public float $squashScale;

    /** Frames the squashed corpse remains before vanishing. */
    #[Property]
    public int $squashFrames;

    #[Hidden]
    public bool $alive = true;

    /** Remaining squash frames once defeated. */
    #[Hidden]
    public int $squashTimer = 0;

    public function __construct(
        float $contactRadius = 1.0,
        float $bodyHeight = 1.2,
        float $stompHeight = 0.6,
        float $bounceVelocity = 0.38,
        int $score = 200,
        float $squashScale = 0.3,
        int $squashFrames = 30,
    ) {
        $this->contactRadius = $contactRadius;
        $this->bodyHeight = $bodyHeight;
        $this->stompHeight = $stompHeight;
        $this->bounceVelocity = $bounceVelocity;
        $this->score = $score;
        $this->squashScale = $squashScale;
        $this->squashFrames = $squashFrames;
    }
}
