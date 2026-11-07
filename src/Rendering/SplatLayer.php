<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * One layer of a splatted ground material ({@see Material::$splatLayers}, shader plan 5.1):
 * its colour (linear, as every albedo - write hex colours through Color::srgb()), its
 * roughness and optionally a procedural pattern ({@see NormalPattern}) whose height field
 * decides, together with the layer's weight in the splat map, where it shows: stones rise
 * through sand at their tops first instead of fading in evenly.
 */
final class SplatLayer
{
    public function __construct(
        public readonly Color $color,
        public readonly float $roughness = 0.8,
        public readonly ?string $pattern = null,
        public readonly float $patternScale = 1.0,
        /** How much the pattern's height lifts the layer in the blend (0 = plain weights). */
        public readonly float $height = 1.0,
    ) {}
}
