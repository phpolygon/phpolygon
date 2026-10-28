<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

readonly class SetDirectionalLight
{
    /**
     * @param float $volumetricScale 0..1, how strongly the light scatters in the
     *                               volumetric fog ({@see \PHPolygon\Component\DirectionalLight::$volumetricScale})
     */
    public function __construct(
        public Vec3 $direction,
        public Color $color,
        public float $intensity,
        public float $volumetricScale = 1.0,
    ) {}
}
