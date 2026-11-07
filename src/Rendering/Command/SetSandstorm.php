<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

/**
 * Blowing sand or dust: the scene fog thickens into a veil of $color from a few
 * metres on, streaked along $windDirection. 0 = none. Issued each frame by
 * Renderer3DSystem from {@see \PHPolygon\Component\Weather::$sandstormIntensity}.
 */
final readonly class SetSandstorm
{
    public function __construct(
        public float $intensity,
        public Color $color,
        public Vec3 $windDirection,
    ) {}
}
