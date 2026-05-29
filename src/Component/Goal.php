<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * The level goal. When a {@see PlatformerController} reaches within
 * {@see $radius}, {@see \PHPolygon\System\GoalSystem} sets the game state to
 * "won" and awards {@see $score} (plus a per-life bonus).
 */
#[Serializable]
#[Category('Gameplay')]
class Goal extends AbstractComponent
{
    /** Reach radius around the goal centre. */
    #[Property]
    public float $radius;

    /** Base score awarded on reaching the goal. */
    #[Property]
    public int $score;

    /** Extra score per remaining life. */
    #[Property]
    public int $lifeBonus;

    #[Hidden]
    public bool $reached = false;

    public function __construct(
        float $radius = 1.8,
        int $score = 1000,
        int $lifeBonus = 200,
    ) {
        $this->radius = $radius;
        $this->score = $score;
        $this->lifeBonus = $lifeBonus;
    }
}
