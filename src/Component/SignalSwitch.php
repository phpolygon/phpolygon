<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Shows or hides this entity (its MeshRenderer and those of its children) from a bool
 * channel: a status lamp, a warning sign, a lit window. Driven by
 * {@see \PHPolygon\System\SignalMotionSystem}; it writes visibility only, never a
 * material or a light, so whoever owns those stays their only writer.
 *
 * On while {@see $channel} is on ('' = always), inverted by {@see $invert}. With
 * {@see $blinkHz} > 0 an "on" lamp blinks: shown for {@see $duty} of each period.
 * The blink clock follows {@see $effect} under reduced motion
 * ({@see \PHPolygon\Motion\MotionTime}); a motion that stands still there shows a
 * steady "on" instead of freezing in the dark half of a period. {@see $maxBlinkHzReduced}
 * > 0 caps the rate while the player asked for reduced motion.
 */
#[Serializable]
#[Category('Motion')]
class SignalSwitch extends AbstractComponent
{
    #[Property]
    public ?int $source = null;

    #[Property]
    public string $channel = '';

    #[Property]
    public bool $invert = false;

    #[Property]
    public float $blinkHz = 0.0;

    #[Property]
    public float $duty = 0.5;

    #[Property]
    public ?string $effect = null;

    #[Property]
    public float $maxBlinkHzReduced = 0.0;

    /** Blink phase in periods (0..1). */
    #[Hidden]
    public float $phase = 0.0;

    /** Visibility written last; null before the first update. */
    #[Hidden]
    public ?bool $shown = null;

    public function __construct(
        string $channel = '',
        bool $invert = false,
        float $blinkHz = 0.0,
        float $duty = 0.5,
        ?int $source = null,
    ) {
        $this->channel = $channel;
        $this->invert = $invert;
        $this->blinkHz = max(0.0, $blinkHz);
        $this->duty = max(0.0, min(1.0, $duty));
        $this->source = $source;
    }
}
