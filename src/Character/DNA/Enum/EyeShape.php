<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete eye-shape choices used by the character DNA system.
 * Each case maps to a procedural eye-socket variant in the character builder.
 */
enum EyeShape
{
    case Round;
    case Almond;
    case Narrow;
    case Wide;
    case Downturned;
    case Upturned;
}
