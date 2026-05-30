<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/** High-level run state of a shooter session. */
enum ShooterStatus: string
{
    case Playing = 'playing';
    case Won = 'won';
    case Lost = 'lost';
}

/**
 * Singleton-style score/lives/status holder for a shooter run. Attach one to a
 * dedicated state entity; the shooter systems read and mutate it
 * ({@see \PHPolygon\System\DamageSystem} adds score and decrements lives) and
 * the HUD reads it for display. Mirrors {@see PlatformerGameState}.
 */
#[Serializable]
#[Category('Gameplay')]
class ShooterGameState extends AbstractComponent
{
    /** Starting (and HUD-displayed) life count. */
    #[Property]
    public int $lives;

    /** Score lost when the player takes a fatal hit. */
    #[Property]
    public int $deathPenalty;

    #[Hidden]
    public int $score = 0;

    #[Hidden]
    public int $wave = 0;

    #[Hidden]
    public ShooterStatus $status = ShooterStatus::Playing;

    public function __construct(
        int $lives = 3,
        int $deathPenalty = 0,
    ) {
        $this->lives = $lives;
        $this->deathPenalty = $deathPenalty;
    }
}
