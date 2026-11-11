<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * One rectangular (optionally arched) opening in a WallWithOpenings slab.
 * x/y is the opening's lower-left corner in wall space (metres from the wall's
 * lower-left corner); $height is the full height including the arch $rise.
 *
 * Sill and lintel are optional ledges in front of the wall face: set
 * $sillHeight / $lintelHeight > 0 to build them. Overhang extends them past
 * the opening on both sides, projection pushes them out of the front face.
 */
final readonly class WallOpening
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $archRise = 0.0,
        public int $archSegments = 12,
        public float $sillHeight = 0.0,
        public float $sillProjection = 0.04,
        public float $sillOverhang = 0.03,
        public float $lintelHeight = 0.0,
        public float $lintelProjection = 0.03,
        public float $lintelOverhang = 0.05,
    ) {}
}
