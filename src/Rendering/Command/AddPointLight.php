<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

readonly class AddPointLight
{
    public function __construct(
        public Vec3 $position,
        public Color $color,
        public float $intensity,
        public float $radius,
        public bool $castsShadow = false,
    ) {}
}
