<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/** The three material slots of a generated plant. */
enum PlantPart: string
{
    case Wood = 'wood';
    case Leaf = 'leaf';
    case Accent = 'accent';
}
