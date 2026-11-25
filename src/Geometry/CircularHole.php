<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A round hole (a regular n-gon prism with a vertical axis) cut out of triangle meshes and convex polygons: the round
 * counterpart of the square {@see PrismClip}, e.g. for a shaft that passes a shell or a lid.
 *
 * The outside of a convex polygon is a union of convex pieces: piece k = the polygon beyond side k of the n-gon inside the
 * wedge between the rays from the centre through its two corners, every cut a half space ({@see PrismClip::clipHalfSpace()}).
 * Neighbouring pieces share a whole ray, so the cut leaves no T-junction at the rim. A polygon that does not reach the circle
 * is returned whole (no needless cut lines). Pure and stateless after construction: safe to share between threads.
 *
 * The hole axis is Y by default (the hole lies in the XZ plane, like every clip in this namespace); `$axis` = 'x' or 'z'
 * turns it: the two in-plane coordinates are then (y, z) or (x, y), in that order, and the algorithm runs on points permuted
 * so that the axis is the middle one (pieces come back in the original coordinates, winding kept).
 */
final class CircularHole
{
    /** Polygons smaller than this (m2) are cut slivers and dropped. */
    private const float MIN_AREA = 1e-11;

    /** @var list<array{0: float, 1: float}> unit outward normals of the n-gon edges */
    private array $normals = [];

    /** Distance from the centre to the edges (m). */
    private float $apothem;

    /** @var array{0: int, 1: int, 2: int} coordinate index of the first in-plane axis, the hole axis, the second in-plane axis */
    private array $map;

    /**
     * @param float $radius circumradius of the n-gon (m): the hole never reaches further than this from the axis
     * @param int $sides    number of rim segments (corner k sits at angle 2 pi k / sides from the first in-plane axis
     *                      towards the second: +X towards +Z for the default axis)
     * @param float $centreU first in-plane coordinate of the axis (x for the axes y and z, y for the axis x)
     * @param float $centreV second in-plane coordinate of the axis (z for the axes y and x, y for the axis z)
     * @param string $axis  the direction the hole runs through: 'y' (default), 'x' or 'z'
     */
    public function __construct(
        public readonly float $centreU,
        public readonly float $centreV,
        public readonly float $radius,
        public readonly int $sides = 24,
        public readonly string $axis = 'y',
    ) {
        if ($sides < 3 || $radius <= 0.0) {
            throw new \InvalidArgumentException('CircularHole needs sides >= 3 and a positive radius.');
        }
        $this->map = match ($axis) {
            'y' => [0, 1, 2],
            'x' => [1, 0, 2],
            'z' => [0, 2, 1],
            default => throw new \InvalidArgumentException("CircularHole: axis must be x, y or z, got {$axis}"),
        };
        $this->apothem = $radius * cos(M_PI / $sides);
        for ($i = 0; $i < $sides; $i++) {
            $a = 2.0 * M_PI * ($i + 0.5) / $sides;
            $this->normals[] = [cos($a), sin($a)];
        }
    }

    /** Whether a point, given by its two in-plane coordinates (seen along the axis), lies inside the hole (the n-gon, edges included). */
    public function contains(float $u, float $v): bool
    {
        foreach ($this->normals as [$nx, $nz]) {
            if ($nx * ($u - $this->centreU) + $nz * ($v - $this->centreV) > $this->apothem) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the bounding box of the polygon (seen from above) touches the circle of the hole.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     */
    public function reaches(array $poly): bool
    {
        $x0 = INF;
        $x1 = -INF;
        $z0 = INF;
        $z1 = -INF;
        [$mu, , $mv] = $this->map;
        foreach ($poly as $p) {
            $x0 = min($x0, $p[$mu]);
            $x1 = max($x1, $p[$mu]);
            $z0 = min($z0, $p[$mv]);
            $z1 = max($z1, $p[$mv]);
        }
        $dx = max($x0 - $this->centreU, 0.0, $this->centreU - $x1);
        $dz = max($z0 - $this->centreV, 0.0, $this->centreV - $z1);

        return $dx * $dx + $dz * $dz < $this->radius * $this->radius;
    }

    /**
     * The part of a convex polygon outside the hole, as convex pieces (winding kept).
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<list<array{0: float, 1: float, 2: float}>>
     */
    public function outside(array $poly): array
    {
        if (!$this->reaches($poly)) {
            return [$poly];
        }
        $local = array_map($this->toLocal(...), $poly);
        $pieces = [];
        for ($k = 0; $k < $this->sides; $k++) {
            $a0 = 2.0 * M_PI * $k / $this->sides;
            $a1 = 2.0 * M_PI * ($k + 1) / $this->sides;
            [$nx, $nz] = $this->normals[$k];
            // The wedge between the rays through corner k and k + 1 (neighbouring pieces share a whole ray), beyond side k.
            $piece = $this->keep($local, -sin($a0), cos($a0), 0.0);
            $piece = count($piece) >= 3 ? $this->keep($piece, sin($a1), -cos($a1), 0.0) : [];
            $piece = count($piece) >= 3 ? $this->keep($piece, $nx, $nz, $this->apothem) : [];
            if (count($piece) >= 3 && self::area($piece) > self::MIN_AREA) {
                $pieces[] = array_map($this->fromLocal(...), $piece);
            }
        }

        return $pieces;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $p
     * @return array{0: float, 1: float, 2: float} the point with the axis in the middle
     */
    private function toLocal(array $p): array
    {
        return [$p[$this->map[0]], $p[$this->map[1]], $p[$this->map[2]]];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $q
     * @return array{0: float, 1: float, 2: float}
     */
    private function fromLocal(array $q): array
    {
        // Every permutation in use is a swap of two axes, so it is its own inverse.
        return [$q[$this->map[0]], $q[$this->map[1]], $q[$this->map[2]]];
    }

    /**
     * Keeps the part of a polygon (axis in the middle, see toLocal) where `nx * (u - cu) + nz * (v - cv) >= c0`.
     *
     * @param list<array{0: float, 1: float, 2: float}> $poly
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private function keep(array $poly, float $nx, float $nz, float $c0): array
    {
        return PrismClip::clipHalfSpace($poly, $nx, $nz, -($nx * $this->centreU + $nz * $this->centreV) - $c0);
    }

    /**
     * Cuts the hole out of every triangle of a mesh; the triangles that stay clear of it are copied untouched, the cut ones
     * are rebuilt as flat pieces with the average vertex normal of their triangle.
     */
    public function cutMesh(MeshData $mesh): MeshData
    {
        $v = $mesh->vertices;
        $n = $mesh->normals;
        $uv = $mesh->uvs;
        $keepV = [];
        $keepN = [];
        $keepUv = [];
        $keepI = [];
        $cut = new MeshAccumulator();
        $count = 0;
        for ($t = 0, $total = count($mesh->indices); $t + 2 < $total; $t += 3) {
            $ids = [$mesh->indices[$t], $mesh->indices[$t + 1], $mesh->indices[$t + 2]];
            $poly = [];
            foreach ($ids as $id) {
                $poly[] = [(float) $v[$id * 3], (float) $v[$id * 3 + 1], (float) $v[$id * 3 + 2]];
            }
            if (!$this->reaches($poly)) {
                foreach ($ids as $id) {
                    array_push($keepV, $v[$id * 3], $v[$id * 3 + 1], $v[$id * 3 + 2]);
                    array_push($keepN, $n[$id * 3], $n[$id * 3 + 1], $n[$id * 3 + 2]);
                    array_push($keepUv, $uv[$id * 2], $uv[$id * 2 + 1]);
                    $keepI[] = $count++;
                }
                continue;
            }
            $hint = [0.0, 0.0, 0.0];
            foreach ($ids as $id) {
                $hint = [$hint[0] + $n[$id * 3], $hint[1] + $n[$id * 3 + 1], $hint[2] + $n[$id * 3 + 2]];
            }
            foreach ($this->outside($poly) as $piece) {
                $cut->fan($piece, $hint);
            }
        }

        return MeshData::merge(new MeshData($keepV, $keepN, $keepUv, $keepI), $cut->build());
    }

    /**
     * The wall of the hole between two positions along the axis, facing the axis (what a flat plate with this hole needs to
     * stay closed).
     */
    public function addWall(MeshAccumulator $m, float $y0, float $y1): void
    {
        for ($i = 0; $i < $this->sides; $i++) {
            $a0 = 2.0 * M_PI * $i / $this->sides;
            $a1 = 2.0 * M_PI * ($i + 1) / $this->sides;
            $p0 = [$this->centreU + $this->radius * cos($a0), $this->centreV + $this->radius * sin($a0)];
            $p1 = [$this->centreU + $this->radius * cos($a1), $this->centreV + $this->radius * sin($a1)];
            $mid = $a0 + M_PI / $this->sides;
            $m->quad(
                $this->fromLocal([$p0[0], $y0, $p0[1]]),
                $this->fromLocal([$p1[0], $y0, $p1[1]]),
                $this->fromLocal([$p1[0], $y1, $p1[1]]),
                $this->fromLocal([$p0[0], $y1, $p0[1]]),
                $this->fromLocal([-cos($mid), 0.0, -sin($mid)]),
            );
        }
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $poly
     */
    private static function area(array $poly): float
    {
        $sx = 0.0;
        $sy = 0.0;
        $sz = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $a = $poly[$i];
            $b = $poly[($i + 1) % $n];
            $sx += $a[1] * $b[2] - $a[2] * $b[1];
            $sy += $a[2] * $b[0] - $a[0] * $b[2];
            $sz += $a[0] * $b[1] - $a[1] * $b[0];
        }

        return 0.5 * sqrt($sx * $sx + $sy * $sy + $sz * $sz);
    }
}
