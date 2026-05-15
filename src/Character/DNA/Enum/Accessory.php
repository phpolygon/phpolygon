<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete cosmetic-accessory choices used by the character DNA system.
 * Each case maps to a procedural accessory mesh variant in the character builder.
 */
enum Accessory
{
    case None;
    case Glasses;
    case Earrings;
    case Headband;
    case Necklace;
}
