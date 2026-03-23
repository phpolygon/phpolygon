<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec2;

class Collision2D
{
    public function __construct(
        public readonly int $entityA,
        public readonly int $entityB,
        public readonly Vec2 $normal,
        public readonly float $penetration,
        public readonly Vec2 $contactPoint,
    ) {}
}
