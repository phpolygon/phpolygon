<?php

declare(strict_types=1);

namespace PHPolygon\Prefab\Vehicles;

enum CarChassis: string
{
    case Sedan   = 'sedan';
    case SUV     = 'suv';
    case Pickup  = 'pickup';
    case Compact = 'compact';
}
