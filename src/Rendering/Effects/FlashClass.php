<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

/** How much an effect changes brightness over time, for the limit that protects players from flashing light. */
enum FlashClass: string
{
    /** No change a player could perceive as flashing. */
    case None = 'none';
    /** Slow or small changes: pulses, glow. */
    case Gentle = 'gentle';
    /** Fast or large changes: flicker, blink, lightning. Has to go through the limit. */
    case Flashing = 'flashing';
}
