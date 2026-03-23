<?php

declare(strict_types=1);

namespace PHPolygon\Event;

use PHPolygon\Physics\Collision2D;

class CollisionEnter
{
    public function __construct(
        public readonly Collision2D $collision,
    ) {}
}
