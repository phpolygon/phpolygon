<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/** High-level run state of a platformer session. */
enum PlatformerStatus: string
{
    case Playing = 'playing';
    case Won = 'won';
    case Lost = 'lost';
}

/**
 * Singleton-style score/lives/status holder for a platformer run. Attach one
 * to a dedicated state entity; the gameplay systems
 * ({@see \PHPolygon\System\CollectibleSystem},
 * {@see \PHPolygon\System\StompSystem}, {@see \PHPolygon\System\GoalSystem},
 * {@see \PHPolygon\System\PlatformerControllerSystem}) read and mutate it, and
 * the game's HUD reads it for display.
 */
#[Serializable]
#[Category('Gameplay')]
class PlatformerGameState extends AbstractComponent
{
    /** Starting (and HUD-displayed) life count. */
    #[Property]
    public int $lives;

    /** Score lost on a death. */
    #[Property]
    public int $deathPenalty;

    #[Hidden]
    public int $coins = 0;

    #[Hidden]
    public int $score = 0;

    #[Hidden]
    public PlatformerStatus $status = PlatformerStatus::Playing;

    public function __construct(
        int $lives = 3,
        int $deathPenalty = 50,
    ) {
        $this->lives = $lives;
        $this->deathPenalty = $deathPenalty;
    }
}
