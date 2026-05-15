<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete nose-shape choices used by the character DNA system.
 * Each case maps to a procedural nose mesh variant in the character builder.
 */
enum NoseShape
{
    case Button;
    case Straight;
    case Wide;
    case Pointed;
    case Hooked;
}
