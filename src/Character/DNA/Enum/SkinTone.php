<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Enum;

/**
 * Discrete skin-tone palette used by the character DNA system.
 * Values are hex colour strings sampled across cool/warm pairs from light to dark.
 */
enum SkinTone: string
{
    case PorcelainCool = '#F5DEC8';
    case PorcelainWarm = '#F0CFB4';
    case LightCool     = '#E5BD9F';
    case LightWarm     = '#E0AC85';
    case MediumCool    = '#C49477';
    case MediumWarm    = '#B47A56';
    case TanWarm       = '#94613F';
    case TanCool       = '#7E5236';
    case DeepWarm      = '#5F3A22';
    case DeepCool      = '#4A2D1A';
    case DarkWarm      = '#36200E';
    case DarkCool      = '#2A1808';
}
