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
        /** Ranks shadow-casting lights for the shadow budget: higher wins, then the nearer to the camera. */
        public int $shadowPriority = 0,
        /** Face size hint of this light's depth cube in texels; 0 = the quality tier's size. Only lowers it. */
        public int $shadowResolution = 0,
    ) {}
}
