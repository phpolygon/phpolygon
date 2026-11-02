<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Effects;

/** Graphics tiers an effect budget is kept for. What a tier means in settings is the game's. */
enum EffectTier: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Ultra = 4;
}
