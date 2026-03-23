<?php

declare(strict_types=1);

namespace PHPolygon\Event;

class TriggerEnter
{
    public function __construct(
        public readonly int $entityA,
        public readonly int $entityB,
    ) {}
}
