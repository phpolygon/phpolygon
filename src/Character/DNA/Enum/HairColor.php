<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete hair-colour palette used by the character DNA system.
 * Values are hex colour strings covering the natural human spectrum plus grey/white.
 */
enum HairColor: string
{
    case Black      = '#1A1612';
    case DarkBrown  = '#3B2515';
    case Brown      = '#6B4226';
    case LightBrown = '#8E6240';
    case DarkBlonde = '#A8845C';
    case Blonde     = '#D4B07A';
    case Platinum   = '#E8D9B0';
    case Auburn     = '#7E3A1F';
    case Red        = '#A84818';
    case Grey       = '#888070';
    case White      = '#E8E4DC';
    case Jet        = '#0E0B08';
}
