<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/** What a plant looks like at {@see GrowthStage::Bare}. */
enum BareStyle: string
{
    /** Bare trunk and branches with fine twigs (a leafless tree). */
    case Twigs = 'twigs';
    /** A cut stump with root flare (a felled or broken plant). */
    case Stump = 'stump';
}
