<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Zone (material) numbers per lattice corner for {@see HeightfieldGround}: 0 = no ground, 1.. = a zone chosen by the
 * caller (grass, sand, ...). It answers which zone a TRIANGLE of the chessboard triangulation belongs to:
 *
 * - a corner without ground (zone 0) makes the triangle void ({@see VOID}), matching the grid's void-corner rule;
 * - three corners of one zone: that zone;
 * - a MIXED triangle (a border between zones runs through it): the most frequent zone among the ground corners of the
 *   window round its cell (default 6 x 6 corners), ties to the lower zone number. The contour of that smoothed share is a
 *   straight line where the source border was straight, whereas the plain majority of three corners turns an oblique border
 *   into a saw of one-cell teeth.
 *
 * Plain data plus a memo of the mixed decisions (a pure function of the data: the same answers in any order). One
 * instance per thread when threads share nothing.
 *
 *     $zones = new HeightfieldZones($i0, $j0, $w, $h, $bytes);
 *     HeightfieldGround::build($grid, [], $zones->closure($grid->cell, [1 => 'grass', 2 => 'sand']));
 */
final class HeightfieldZones
{
    public const int VOID = 0;

    /** @var array<int, int> decided mixed triangles (key: cell and triangle) */
    private array $mixed = [];

    /**
     * @param int $i0 lattice index of the first corner column
     * @param int $j0 lattice index of the first corner row
     * @param int $width corner columns
     * @param int $height corner rows
     * @param string $zones one byte per corner, row-major (z outer): the zone number 0..255
     * @param int $before corners before the cell in the smoothing window (default 2: window = 6 x 6 corners)
     * @param int $after corners after the cell's upper corner in the window (default 3 counting from the lower corner)
     */
    public function __construct(
        public readonly int $i0,
        public readonly int $j0,
        public readonly int $width,
        public readonly int $height,
        public readonly string $zones,
        public readonly int $before = 2,
        public readonly int $after = 3,
    ) {
        if ($width < 1 || $height < 1 || strlen($zones) !== $width * $height) {
            throw new \InvalidArgumentException('HeightfieldZones: needs one byte per corner (width * height)');
        }
    }

    /** Zone of corner (i, j): {@see VOID} outside the stored lattice. */
    public function zone(int $i, int $j): int
    {
        $ci = $i - $this->i0;
        $cj = $j - $this->j0;
        if ($ci < 0 || $cj < 0 || $ci >= $this->width || $cj >= $this->height) {
            return self::VOID;
        }

        return \ord($this->zones[$cj * $this->width + $ci]);
    }

    /** Zone of triangle `$which` of cell (ia, ja): {@see VOID} unless all three corners have ground. */
    public function triangleZone(int $ia, int $ja, int $which): int
    {
        $first = null;
        $same = true;
        foreach (HeightfieldGrid::triangleCorners($ia, $ja, $which) as [$i, $j]) {
            $z = $this->zone($i, $j);
            if ($z === self::VOID) {
                return self::VOID;
            }
            $first ??= $z;
            $same = $same && $z === $first;
        }
        if ($same) {
            return (int) $first;
        }
        $key = ($ja << 33) ^ ($ia << 2) ^ $which;
        if (isset($this->mixed[$key])) {
            return $this->mixed[$key];
        }
        $count = [];
        for ($dj = -$this->before; $dj <= $this->after; $dj++) {
            for ($di = -$this->before; $di <= $this->after; $di++) {
                $z = $this->zone($ia + $di, $ja + $dj);
                if ($z !== self::VOID) {
                    $count[$z] = ($count[$z] ?? 0) + 1;
                }
            }
        }
        $best = (int) $first;
        $bestCount = -1;
        ksort($count);
        foreach ($count as $z => $n) {
            if ($n > $bestCount) {
                $best = $z;
                $bestCount = $n;
            }
        }

        return $this->mixed[$key] = $best;
    }

    /**
     * The zone function {@see HeightfieldGround::build()} wants: `(x, z) => zone name`, answered per triangle at its centroid.
     * Asked for a void triangle (which a masked grid never builds) it returns the name of zone 0, or '' without one.
     *
     * @param array<int, string> $names zone number => name (a number without a name is called 'zone<N>')
     * @return \Closure(float, float): string
     */
    public function closure(float $cell, array $names): \Closure
    {
        return function (float $x, float $z) use ($cell, $names): string {
            $ia = (int) floor($x / $cell);
            $ja = (int) floor($z / $cell);
            $u = $x / $cell - $ia;
            $v = $z / $cell - $ja;
            $which = HeightfieldGrid::diagonalRising($ia, $ja) ? ($v >= $u ? 0 : 1) : ($u + $v <= 1.0 ? 0 : 1);
            $zone = $this->triangleZone($ia, $ja, $which);

            return $names[$zone] ?? ($zone === self::VOID ? '' : 'zone' . $zone);
        };
    }

    /** Content hash (xxh128 hex) for cache keys: origin, size, window and every zone byte. */
    public function contentHash(): string
    {
        return hash('xxh128', pack('qqVVVV', $this->i0, $this->j0, $this->width, $this->height, $this->before, $this->after) . $this->zones);
    }
}
