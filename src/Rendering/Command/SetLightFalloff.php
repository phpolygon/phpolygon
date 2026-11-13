<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Light falloff of the point and spot lights of the frame. Default (no command, or $physical false): the smooth
 * window (1 - d^2/r^2)^2, intensities are a free scale. With $physical: the inverse square law with a smooth
 * window at the light's radius, so a light's intensity is the radiance it gives at 1 m (candela-like) and
 * doubling the distance quarters the light, as in a photograph. Pair it with a fixed exposure (ImageLook).
 * Sun, rect lights and emission are unaffected.
 */
readonly class SetLightFalloff
{
    public function __construct(public bool $physical = true) {}
}
