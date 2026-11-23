<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * One transverse section of a {@see HullLoftMesh}: the half-outline of the hull
 * at length position $z, listed from the lowest point up to the top edge.
 *
 * Every point is [halfBreadth, y] with halfBreadth >= 0 (distance from the
 * centre plane x = 0). The same number of points is needed at every station of
 * one hull. A station whose points all have halfBreadth 0 is a knife-edge end
 * (stem or stern post); points that run out before the others (a lower level
 * that already closed on the centre line) give a raked stem automatically.
 */
final class HullStation
{
    /**
     * @param list<array{0: float, 1: float}> $points [halfBreadth, y], bottom to top.
     */
    public function __construct(
        public readonly float $z,
        public readonly array $points,
    ) {
    }
}
