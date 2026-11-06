<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

/**
 * A one-sided rectangular area light in world space: centre and the two half-extent
 * axes; it emits along normalize(cross(axisX, axisY)). See
 * {@see \PHPolygon\Component\RectAreaLight}.
 */
readonly class AddRectLight
{
    public function __construct(
        public Vec3 $center,
        public Vec3 $axisX,
        public Vec3 $axisY,
        public Color $color,
        public float $intensity,
        public float $range,
    ) {}
}
