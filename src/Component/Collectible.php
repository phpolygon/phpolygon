<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * A pickup: when a {@see PlatformerController} comes within {@see $radius}, the
 * collectible is flagged and the entity destroyed, its {@see $score} is added
 * and {@see $coinValue} counted. Driven by
 * {@see \PHPolygon\System\CollectibleSystem}.
 */
#[Serializable]
#[Category('Gameplay')]
class Collectible extends AbstractComponent
{
    /** Score awarded on pickup. */
    #[Property]
    public int $score;

    /** How many "coins" (or gems, …) this counts as on the HUD. */
    #[Property]
    public int $coinValue;

    /** Pickup radius around the collectible centre. */
    #[Property]
    public float $radius;

    /** Vertical offset added to the player centre when measuring distance. */
    #[Property]
    public float $playerYOffset;

    #[Hidden]
    public bool $collected = false;

    public function __construct(
        int $score = 100,
        int $coinValue = 1,
        float $radius = 1.2,
        float $playerYOffset = 0.4,
    ) {
        $this->score = $score;
        $this->coinValue = $coinValue;
        $this->radius = $radius;
        $this->playerYOffset = $playerYOffset;
    }
}
