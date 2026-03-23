<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec2;

class RaycastHit2D
{
    public function __construct(
        public readonly int $entityId,
        public readonly Vec2 $point,
        public readonly Vec2 $normal,
        public readonly float $distance,
    ) {}
}
