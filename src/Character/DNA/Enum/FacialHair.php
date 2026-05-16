<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete facial-hair choices used by the character DNA system.
 * Each case maps to a procedural facial-hair mesh variant in the character builder.
 */
enum FacialHair
{
    case None;
    case Stubble;
    case Mustache;
    case Goatee;
    case FullBeard;
    case Sideburns;
}
