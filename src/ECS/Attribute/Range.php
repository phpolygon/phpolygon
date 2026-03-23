<?php

declare(strict_types=1);

namespace PHPolygon\ECS\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Range
{
    public function __construct(
        public readonly float $min = 0.0,
        public readonly float $max = 1.0,
    ) {}
}
