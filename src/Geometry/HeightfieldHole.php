<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A convex hole through a {@see HeightfieldGround}, as plain data (a closed polygon in the XZ plane, no
 * closure): a lift shaft, a pit, a pool. The outline is cut exactly into the triangles it crosses.
 * Several holes are several objects; a concave opening is a few convex ones.
 *
 * A circle is an inscribed regular polygon of `$segments` sides (its vertices lie on the circle).
 */
final class HeightfieldHole
{
    /** @var list<array{0: float, 1: float}> corners, counter-clockwise seen with X right and Z up */
    public readonly array $points;

    /** @var list<array{0: float, 1: float, 2: float}> per edge A, B, C of the half plane A x + B z + C >= 0 (inside); (A, B) is a unit vector */
    private array $planes = [];

    /**
     * @param list<array{0: float, 1: float}> $points at least three corners of a convex polygon, either winding
     */
    private function __construct(array $points)
    {
        $n = count($points);
        if ($n < 3) {
            throw new \InvalidArgumentException('HeightfieldHole: needs at least three corners');
        }
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = $points[$i];
            $b = $points[($i + 1) % $n];
            $sum += $a[0] * $b[1] - $b[0] * $a[1];
        }
        if (abs($sum) < 1e-9) {
            throw new \InvalidArgumentException('HeightfieldHole: the outline has no area');
        }
        if ($sum < 0.0) {
            $points = array_reverse($points);
        }
        $this->points = $points;
        for ($i = 0; $i < $n; $i++) {
            $a = $this->points[$i];
            $b = $this->points[($i + 1) % $n];
            $ex = $b[0] - $a[0];
            $ez = $b[1] - $a[1];
            $len = hypot($ex, $ez);
            if ($len < 1e-9) {
                throw new \InvalidArgumentException('HeightfieldHole: two corners coincide');
            }
            $this->planes[] = [-$ez / $len, $ex / $len, ($ez * $a[0] - $ex * $a[1]) / $len];
        }
        // Convex: every corner on the inner side of every edge.
        foreach ($this->planes as $k => [$pa, $pb, $pc]) {
            foreach ($this->points as $p) {
                if ($pa * $p[0] + $pb * $p[1] + $pc < -1e-9 * max(1.0, abs($p[0]) + abs($p[1]))) {
                    throw new \InvalidArgumentException("HeightfieldHole: not convex at edge {$k}");
                }
            }
        }
    }

    /** A rectangle: centre, size along its own u axis and along w, turned by $yaw radians (u = (cos yaw, sin yaw) in XZ). */
    public static function rect(float $x, float $z, float $width, float $depth, float $yaw = 0.0): self
    {
        if ($width <= 0.0 || $depth <= 0.0) {
            throw new \InvalidArgumentException('HeightfieldHole::rect: width and depth must be > 0');
        }
        $c = cos($yaw);
        $s = sin($yaw);
        $pts = [];
        foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$su, $sw]) {
            $u = $su * $width * 0.5;
            $w = $sw * $depth * 0.5;
            $pts[] = [$x + $u * $c - $w * $s, $z + $u * $s + $w * $c];
        }

        return new self($pts);
    }

    /** A circle as a regular polygon whose first corner points along +X. */
    public static function circle(float $x, float $z, float $radius, int $segments = 24): self
    {
        if ($radius <= 0.0 || $segments < 3) {
            throw new \InvalidArgumentException('HeightfieldHole::circle: radius must be > 0 and segments >= 3');
        }
        $pts = [];
        for ($k = 0; $k < $segments; $k++) {
            $a = 2.0 * M_PI * $k / $segments;
            $pts[] = [$x + $radius * cos($a), $z + $radius * sin($a)];
        }

        return new self($pts);
    }

    /** @param list<array{0: float, 1: float}> $points corners of any convex polygon */
    public static function convex(array $points): self
    {
        return new self($points);
    }

    /**
     * Inside-positive distance to the edge lines: for each edge `[A, B, C]`, `A x + B z + C` is the
     * distance from the edge line, positive on the hole side.
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public function planes(): array
    {
        return $this->planes;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} minX, minZ, maxX, maxZ */
    public function bounds(): array
    {
        $minX = $minZ = INF;
        $maxX = $maxZ = -INF;
        foreach ($this->points as [$x, $z]) {
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minZ = min($minZ, $z);
            $maxZ = max($maxZ, $z);
        }

        return [$minX, $minZ, $maxX, $maxZ];
    }

    public function area(): float
    {
        $n = count($this->points);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = $this->points[$i];
            $b = $this->points[($i + 1) % $n];
            $sum += $a[0] * $b[1] - $b[0] * $a[1];
        }

        return $sum * 0.5;
    }

    /** Distance to the outline: negative inside, positive outside, 0 on it. */
    public function signedDistance(float $x, float $z): float
    {
        $inside = true;
        $nearest = INF;
        $n = count($this->points);
        for ($i = 0; $i < $n; $i++) {
            $d = $this->planes[$i][0] * $x + $this->planes[$i][1] * $z + $this->planes[$i][2];
            if ($d < 0.0) {
                $inside = false;
            }
            $a = $this->points[$i];
            $b = $this->points[($i + 1) % $n];
            $nearest = min($nearest, RegionShape::segmentDistance($x, $z, $a[0], $a[1], $b[0], $b[1]));
        }

        return $inside ? -$nearest : $nearest;
    }

    /** Content hash (xxh128 hex) of the outline: the corners as little-endian doubles. */
    public function fingerprint(): string
    {
        $flat = [];
        foreach ($this->points as [$x, $z]) {
            $flat[] = $x;
            $flat[] = $z;
        }

        return hash('xxh128', pack('V', count($this->points)) . pack('e*', ...$flat));
    }
}
