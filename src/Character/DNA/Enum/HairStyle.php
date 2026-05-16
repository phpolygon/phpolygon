<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete hairstyle choices used by the character DNA system.
 * Each case maps to a procedural hair mesh variant in the character builder.
 */
enum HairStyle
{
    case Bald;
    case BuzzCut;
    case Short;
    case ShortCurly;
    case Medium;
    case Long;
    case Ponytail;
    case Topknot;
    case Mohawk;
    case Braided;
    case Dreadlocks;
    case Mullet;
}
