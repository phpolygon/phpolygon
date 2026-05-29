<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

readonly class AddSpotLight
{
    public function __construct(
        public Vec3 $position,
        public Vec3 $direction,
        public Color $color,
        public float $intensity,
        public float $range,
        public float $angle,
        public float $penumbra,
    ) {}
}
