<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A 2D outline in the XZ plane as a signed distance field (< 0 inside, 0 on the
 * outline, > 0 outside, in metres) – the input of {@see ConformingRegionMesh}
 * and {@see ConformingSurfaceSet}.
 *
 * Shapes: {@see circle()}, {@see rect()} (optionally turned about Y),
 * {@see polygon()}, {@see polyline()} (a path: centre line + half width, round
 * ends and joints), {@see halfDisc()} (a disc cut by a straight edge) and
 * {@see field()} for any other distance function with its bounds.
 *
 * Exact Euclidean distances (not just a sign) matter: a hem or a border band is
 * cut at "distance = width", so a distorted field gives a hem of uneven width.
 */
final class RegionShape
{
    private const int CIRCLE = 0;
    private const int RECT = 1;
    private const int POLYGON = 2;
    private const int POLYLINE = 3;
    private const int HALF_DISC = 4;
    private const int FIELD = 5;

    /**
     * @param list<float> $p shape parameters (layout per kind)
     * @param list<array{float, float}> $points
     * @param (\Closure(float, float): float)|null $fn
     * @param array{float, float, float, float} $box
     */
    private function __construct(
        private readonly int $kind,
        private readonly array $p,
        private readonly array $points,
        private readonly ?\Closure $fn,
        private readonly array $box,
    ) {
    }

    public static function circle(float $x, float $z, float $radius): self
    {
        if ($radius <= 0.0) {
            throw new \InvalidArgumentException('RegionShape::circle: radius must be > 0');
        }

        return new self(self::CIRCLE, [$x, $z, $radius], [], null, [$x - $radius, $z - $radius, $x + $radius, $z + $radius]);
    }

    /**
     * A rectangle of $width (along local X) by $depth (along local Z) around
     * its centre, turned by $yaw radians about +Y (positive = from +X toward −Z,
     * the same sense as a Y rotation of a mesh).
     */
    public static function rect(float $x, float $z, float $width, float $depth, float $yaw = 0.0): self
    {
        if ($width <= 0.0 || $depth <= 0.0) {
            throw new \InvalidArgumentException('RegionShape::rect: width and depth must be > 0');
        }
        $c = cos($yaw);
        $s = sin($yaw);
        $ex = abs($c) * $width / 2.0 + abs($s) * $depth / 2.0;
        $ez = abs($s) * $width / 2.0 + abs($c) * $depth / 2.0;

        return new self(self::RECT, [$x, $z, $width / 2.0, $depth / 2.0, $c, $s], [], null, [$x - $ex, $z - $ez, $x + $ex, $z + $ez]);
    }

    /**
     * A closed polygon (either winding, last point not repeated). The sign
     * follows the even-odd rule, so a self-intersecting outline still has an
     * inside – but keep outlines simple for a clean result.
     *
     * @param list<array{float, float}> $points
     */
    public static function polygon(array $points): self
    {
        if (count($points) < 3) {
            throw new \InvalidArgumentException('RegionShape::polygon: needs at least 3 points');
        }

        return new self(self::POLYGON, [], $points, null, self::pointBounds($points, 0.0));
    }

    /**
     * A path: everything within $halfWidth of the centre line (round caps and
     * joints, so bends never pinch or overshoot).
     *
     * @param list<array{float, float}> $points at least 2
     */
    public static function polyline(array $points, float $halfWidth): self
    {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('RegionShape::polyline: needs at least 2 points');
        }
        if ($halfWidth <= 0.0) {
            throw new \InvalidArgumentException('RegionShape::polyline: half width must be > 0');
        }

        return new self(self::POLYLINE, [$halfWidth], $points, null, self::pointBounds($points, $halfWidth));
    }

    /**
     * A disc cut by a straight line through its centre: the half that lies in
     * direction ($openX, $openZ) from the centre stays (e.g. a semicircular
     * landing that opens toward a door).
     */
    public static function halfDisc(float $x, float $z, float $radius, float $openX, float $openZ): self
    {
        $len = hypot($openX, $openZ);
        if ($radius <= 0.0 || $len < 1e-9) {
            throw new \InvalidArgumentException('RegionShape::halfDisc: radius must be > 0 and the direction non-zero');
        }

        return new self(self::HALF_DISC, [$x, $z, $radius, $openX / $len, $openZ / $len], [], null, [$x - $radius, $z - $radius, $x + $radius, $z + $radius]);
    }

    /**
     * Any signed distance function; the bounds enclose its inside (field < 0).
     *
     * @param callable(float, float): float $distance
     */
    public static function field(float $minX, float $minZ, float $maxX, float $maxZ, callable $distance): self
    {
        if ($maxX < $minX || $maxZ < $minZ) {
            throw new \InvalidArgumentException('RegionShape::field: empty bounds');
        }

        return new self(self::FIELD, [], [], \Closure::fromCallable($distance), [$minX, $minZ, $maxX, $maxZ]);
    }

    /**
     * Bounds of the inside: [minX, minZ, maxX, maxZ].
     *
     * @return array{float, float, float, float}
     */
    public function bounds(): array
    {
        return $this->box;
    }

    /** Signed distance to the outline at (x, z): < 0 inside. */
    public function distance(float $x, float $z): float
    {
        $p = $this->p;
        switch ($this->kind) {
            case self::CIRCLE:
                return hypot($x - $p[0], $z - $p[1]) - $p[2];
            case self::RECT:
                $dx = $x - $p[0];
                $dz = $z - $p[1];
                // into the rectangle's frame (inverse of the yaw)
                $lx = $dx * $p[4] - $dz * $p[5];
                $lz = $dx * $p[5] + $dz * $p[4];
                $qx = abs($lx) - $p[2];
                $qz = abs($lz) - $p[3];

                return hypot(max($qx, 0.0), max($qz, 0.0)) + min(max($qx, $qz), 0.0);
            case self::POLYGON:
                return self::polygonDistance($this->points, $x, $z);
            case self::POLYLINE:
                $best = INF;
                $pts = $this->points;
                for ($i = 1, $n = count($pts); $i < $n; $i++) {
                    $best = min($best, self::segmentDistance($x, $z, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]));
                }

                return $best - $p[0];
            case self::HALF_DISC:
                $dx = $x - $p[0];
                $dz = $z - $p[1];

                return max(hypot($dx, $dz) - $p[2], -($dx * $p[3] + $dz * $p[4]));
            default:
                $fn = $this->fn;

                return $fn === null ? INF : $fn($x, $z);
        }
    }

    /**
     * Rectangles to stamp this shape into a grid with, each grown by $margin
     * (the field must be known that far outside the outline – at least the hem
     * width plus two cells). A path stamps one small rectangle per segment
     * instead of one large box, so a long diagonal path stays cheap.
     *
     * @return list<array{float, float, float, float, \Closure(float, float): float}>
     */
    public function stamps(float $margin): array
    {
        if ($this->kind !== self::POLYLINE) {
            [$a, $b, $c, $d] = $this->box;

            return [[$a - $margin, $b - $margin, $c + $margin, $d + $margin, $this->distance(...)]];
        }
        $h = $this->p[0];
        $m = $h + $margin;
        $out = [];
        $pts = $this->points;
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            [$ax, $az] = $pts[$i - 1];
            [$bx, $bz] = $pts[$i];
            $out[] = [min($ax, $bx) - $m, min($az, $bz) - $m, max($ax, $bx) + $m, max($az, $bz) + $m,
                static fn (float $x, float $z): float => self::segmentDistance($x, $z, $ax, $az, $bx, $bz) - $h];
        }

        return $out;
    }

    /** Distance from (x, z) to the segment a–b. */
    public static function segmentDistance(float $x, float $z, float $ax, float $az, float $bx, float $bz): float
    {
        $dx = $bx - $ax;
        $dz = $bz - $az;
        $len2 = $dx * $dx + $dz * $dz;
        $t = $len2 > 0.0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($z - $az) * $dz) / $len2)) : 0.0;

        return hypot($x - $ax - $dx * $t, $z - $az - $dz * $t);
    }

    /**
     * @param list<array{float, float}> $pts
     */
    private static function polygonDistance(array $pts, float $x, float $z): float
    {
        $best = INF;
        $inside = false;
        $n = count($pts);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$ax, $az] = $pts[$j];
            [$bx, $bz] = $pts[$i];
            $best = min($best, self::segmentDistance($x, $z, $ax, $az, $bx, $bz));
            if ((($bz > $z) !== ($az > $z)) && $x < ($ax - $bx) * ($z - $bz) / ($az - $bz) + $bx) {
                $inside = !$inside;
            }
        }

        return $inside ? -$best : $best;
    }

    /**
     * @param list<array{float, float}> $pts
     * @return array{float, float, float, float}
     */
    private static function pointBounds(array $pts, float $grow): array
    {
        $b = [INF, INF, -INF, -INF];
        foreach ($pts as [$x, $z]) {
            $b = [min($b[0], $x), min($b[1], $z), max($b[2], $x), max($b[3], $z)];
        }

        return [$b[0] - $grow, $b[1] - $grow, $b[2] + $grow, $b[3] + $grow];
    }
}
