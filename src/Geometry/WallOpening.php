<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec2;

/**
 * One opening in a WallWithOpenings slab: a rectangle (optionally arched at the
 * top) or, via ring()/circle(), a free polygon such as a porthole.
 *
 * x/y is the opening's lower-left corner in wall space (metres from the wall's
 * lower-left corner); $height is the full height including the arch $rise. For a
 * ring opening x/y/width/height are the bounding box of the ring.
 *
 * Floor-level openings (doors, shopfronts) have y = 0: they are cut as a notch
 * in the wall's bottom edge. They may also touch the left or right edge
 * (x = 0 or x + width = wall width). Only rectangular/arched openings can be
 * floor-level; ring openings must lie strictly inside the wall.
 *
 * Sill and lintel are optional ledges in front of the wall face: set
 * $sillHeight / $lintelHeight > 0 to build them. Overhang extends them past
 * the opening on both sides, projection pushes them out of the front face. A
 * floor-level opening has no sill (there is nothing below it). A ring opening
 * gets them across its bounding box.
 */
final readonly class WallOpening
{
    /**
     * @param ?list<Vec2> $ring Polygon in wall space (>= 3 points, simple); when set the
     *                          rectangle/arch parameters are ignored for the shape.
     */
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
        public ?array $ring = null,
    ) {}

    /**
     * An opening shaped like an arbitrary polygon given in wall space.
     *
     * @param list<Vec2> $ring >= 3 points, first not repeated, any winding
     */
    public static function ring(
        array $ring,
        float $sillHeight = 0.0,
        float $sillProjection = 0.04,
        float $sillOverhang = 0.03,
        float $lintelHeight = 0.0,
        float $lintelProjection = 0.03,
        float $lintelOverhang = 0.05,
    ): self {
        if (count($ring) < 3) {
            throw new \InvalidArgumentException('A ring opening needs at least 3 points');
        }
        $minX = $maxX = $ring[0]->x;
        $minY = $maxY = $ring[0]->y;
        foreach ($ring as $p) {
            $minX = min($minX, $p->x);
            $maxX = max($maxX, $p->x);
            $minY = min($minY, $p->y);
            $maxY = max($maxY, $p->y);
        }
        return new self(
            $minX, $minY, $maxX - $minX, $maxY - $minY,
            0.0, 12, $sillHeight, $sillProjection, $sillOverhang, $lintelHeight, $lintelProjection, $lintelOverhang,
            $ring,
        );
    }

    /** A round opening (porthole) of $radius centred at ($cx, $cy) in wall space. */
    public static function circle(
        float $cx,
        float $cy,
        float $radius,
        int $sides = 24,
        float $sillHeight = 0.0,
        float $sillProjection = 0.04,
        float $sillOverhang = 0.03,
        float $lintelHeight = 0.0,
        float $lintelProjection = 0.03,
        float $lintelOverhang = 0.05,
    ): self {
        $ring = [];
        foreach (Profile::circle($radius, $sides) as $p) {
            $ring[] = new Vec2($cx + $p->x, $cy + $p->y);
        }
        return self::ring($ring, $sillHeight, $sillProjection, $sillOverhang, $lintelHeight, $lintelProjection, $lintelOverhang);
    }

    /** True for a rectangular/arched opening whose bottom edge lies on the wall's bottom edge. */
    public function isFloorLevel(): bool
    {
        return $this->ring === null && $this->y <= 1e-9;
    }
}
