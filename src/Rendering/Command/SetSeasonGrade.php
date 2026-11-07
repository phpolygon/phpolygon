<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Rendering\Color;

/**
 * The season's colour map for vegetation (grass blades and two-sided translucent
 * foliage): desaturate by $desaturation, then multiply by $tint. Identity
 * (white, 0) leaves plants as authored. Issued each frame from
 * {@see \PHPolygon\Component\Season}.
 */
final readonly class SetSeasonGrade
{
    public function __construct(
        public Color $tint,
        public float $desaturation = 0.0,
    ) {}
}
