<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A terrain height function sampled on a regular lattice, as plain data: the origin as lattice
 * indices, the cell count, the cell size and the heights – nothing else. That is what makes the
 * ground build pure and thread-safe: sample once (the one step that calls the height function), then
 * hand the grid to {@see HeightfieldGround::build()} in any process or worker, or store it.
 *
 *     $grid = HeightfieldGrid::sample(-700, -700, 700, 700, 2.0, $terrain->heightAt(...));
 *
 * Lattice corners sit at `index * cell` for an absolute integer index (not at `origin + i * cell`), so
 * two grids of the same cell size – neighbouring tiles, or a {@see ConformingRegionMesh} –
 * compute bit-identical corner positions and the same heights where they meet. The grid also carries a
 * one-cell halo outside its bounds: normals on its border are taken from the real surface, so a
 * neighbouring tile shades the shared border the same way.
 *
 * Between corners the surface is two planar triangles per cell, the diagonal alternating like a
 * chessboard (no direction is preferred); {@see heightAt()} answers on exactly that surface.
 *
 * MASK. A grid may say that some corners have no ground (a coast, a void): `$ground` holds one byte per
 * corner (1 = ground, 0 = none; null = ground everywhere). A triangle exists only if all three of its
 * corners have ground ({@see triangleHasGround()}), so no triangle puts ground on a lattice point that
 * has none. The stored height of a groundless corner is a FILL (the mean of the nearest ground corners,
 * see {@see sample()}), so normals along the edge of the ground come from the slope of the land and not
 * from a made-up cliff; {@see groundHeightAt()} answers null where the surface has no triangle. A grid
 * without a mask behaves exactly as it always did.
 */
final class HeightfieldGrid
{
    /** Bump when the sampling or the layout of the data changes. */
    public const int FORMAT = 1;

    /** Rings of corners searched for the fill height of a groundless corner. */
    public const int FILL_RINGS = 4;

    /** Reach (m) around a query point inside which neighbouring cells are tested too (a point on an edge belongs to both sides). */
    private const float EDGE_PAD = 1.0e-4;

    /** Barycentric tolerance of {@see groundHeightAt()}. */
    private const float BARY_TOLERANCE = 1.0e-6;

    /**
     * @param int $i0 lattice index of the first corner column (corner x = $i0 * $cell)
     * @param int $j0 lattice index of the first corner row (corner z = $j0 * $cell)
     * @param int $nx cells along X (corners: $nx + 1, plus the halo)
     * @param int $nz cells along Z
     * @param list<float> $heights (nx + 3) * (nz + 3) heights, row-major, starting one corner before ($i0, $j0)
     * @param string|null $ground one byte per height, same layout: "\x01" ground, "\x00" none; null = ground everywhere
     * @param string|null $diagonals best-fit diagonals, one byte per cell in the layout of the heights (cell (i, j) at the index of
     *                               corner (i, j)): "\x01" = from (i, j) to (i + 1, j + 1), "\x00" = the other one; null = the chessboard
     */
    public function __construct(
        public readonly int $i0,
        public readonly int $j0,
        public readonly int $nx,
        public readonly int $nz,
        public readonly float $cell,
        public readonly array $heights,
        public readonly ?string $ground = null,
        public readonly ?string $diagonals = null,
    ) {
        if ($cell <= 0.0 || $nx < 1 || $nz < 1) {
            throw new \InvalidArgumentException('HeightfieldGrid: needs cell > 0 and at least one cell');
        }
        if (count($heights) !== ($nx + 3) * ($nz + 3)) {
            throw new \InvalidArgumentException('HeightfieldGrid: expected ' . (($nx + 3) * ($nz + 3)) . ' heights, got ' . count($heights));
        }
        if ($ground !== null && strlen($ground) !== count($heights)) {
            throw new \InvalidArgumentException('HeightfieldGrid: the ground mask needs one byte per height');
        }
        if ($diagonals !== null && strlen($diagonals) !== count($heights)) {
            throw new \InvalidArgumentException('HeightfieldGrid: the diagonals need one byte per height position');
        }
    }

    /**
     * Sample $height over the bounds, grown to whole cells (the origin snaps to a multiple of $cell,
     * the same rule as {@see ConformingRegionMesh::covering()}). Calls $height once per corner,
     * halo included, in a fixed order. The function may return null for a corner without ground: the grid
     * then carries a mask, and the corner's stored height is the mean of the nearest ground corners (ring by
     * ring, up to {@see FILL_RINGS}; corners beyond the grid's halo are asked from `$height` too, so two tiles
     * fill a shared border alike), 0 when there is none.
     *
     * `$bestFitDiagonal` picks the diagonal of every cell by the smaller height error against the function at the
     * cell centre (see {@see risingAt()}); that costs one more call per cell (and one ring of halo cells), made after
     * the corners, in row order.
     *
     * @param callable(float, float): (float|null) $height
     */
    public static function sample(float $minX, float $minZ, float $maxX, float $maxZ, float $cell, callable $height, bool $bestFitDiagonal = false): self
    {
        if ($cell <= 0.0 || $maxX <= $minX || $maxZ <= $minZ) {
            throw new \InvalidArgumentException('HeightfieldGrid::sample: empty bounds or cell <= 0');
        }
        $i0 = (int) floor($minX / $cell + 1e-9);
        $j0 = (int) floor($minZ / $cell + 1e-9);
        $nx = max(1, (int) ceil($maxX / $cell - 1e-9) - $i0);
        $nz = max(1, (int) ceil($maxZ / $cell - 1e-9) - $j0);
        $heights = [];
        $void = [];
        $width = $nx + 3;
        $k = 0;
        for ($j = $j0 - 1; $j <= $j0 + $nz + 1; $j++) {
            $z = $j * $cell;
            for ($i = $i0 - 1; $i <= $i0 + $nx + 1; $i++) {
                $h = $height($i * $cell, $z);
                if ($h === null) {
                    $void[$k] = true;
                    $heights[] = 0.0;
                } else {
                    $heights[] = (float) $h;
                }
                $k++;
            }
        }
        if ($void === []) {
            return new self($i0, $j0, $nx, $nz, $cell, $heights, null, $bestFitDiagonal ? self::fitDiagonals($i0, $j0, $nx, $nz, $cell, $heights, null, $height) : null);
        }

        // Raw ground height of any corner, inside the array or (cached) from the function.
        $outside = [];
        $raw = static function (int $i, int $j) use ($i0, $j0, $nz, $width, &$heights, &$void, &$outside, $height, $cell): ?float {
            $ci = $i - $i0 + 1;
            $cj = $j - $j0 + 1;
            if ($ci >= 0 && $cj >= 0 && $ci < $width && $cj < $nz + 3) {
                $idx = $cj * $width + $ci;

                return isset($void[$idx]) ? null : $heights[$idx];
            }
            $key = $j . ':' . $i;
            if (!array_key_exists($key, $outside)) {
                $v = $height($i * $cell, $j * $cell);
                $outside[$key] = $v === null ? null : (float) $v;
            }

            return $outside[$key];
        };
        $filled = $heights;
        $mask = str_repeat("\x01", count($heights));
        foreach (array_keys($void) as $idx) {
            $mask[$idx] = "\x00";
            $i = $i0 - 1 + $idx % $width;
            $j = $j0 - 1 + intdiv($idx, $width);
            $filled[$idx] = 0.0;
            for ($r = 1; $r <= self::FILL_RINGS; $r++) {
                $sum = 0.0;
                $n = 0;
                for ($dj = -$r; $dj <= $r; $dj++) {
                    for ($di = -$r; $di <= $r; $di++) {
                        if (max(abs($di), abs($dj)) !== $r) {
                            continue;
                        }
                        $q = $raw($i + $di, $j + $dj);
                        if ($q !== null) {
                            $sum += $q;
                            $n++;
                        }
                    }
                }
                if ($n > 0) {
                    $filled[$idx] = $sum / $n;
                    break;
                }
            }
        }

        $filled = array_values($filled);

        return new self($i0, $j0, $nx, $nz, $cell, $filled, $mask, $bestFitDiagonal ? self::fitDiagonals($i0, $j0, $nx, $nz, $cell, $filled, $mask, $height) : null);
    }

    /**
     * A grid from the corners of the grid itself (no halo given): `$corners` is (nx + 1) * (nz + 1) heights,
     * row-major from ($i0, $j0), null = no ground. The halo is made like {@see sample()} makes it, from the
     * corners inside the array only.
     *
     * For ONE grid (or tiles that do not need to shade their shared border alike). Two tiles cut from one stored
     * lattice this way fill their halo corners from different neighbours and compute different border normals: for
     * tiles call {@see sample()} with a function that looks into the whole lattice (it answers the halo and the fill
     * rings from real data).
     *
     * `$diagonals` = the best-fit diagonals of the cells of this grid (one byte per cell, row-major from ($i0, $j0),
     * "\x01" = from (i, j) to (i + 1, j + 1), "\x00" = the other one), e.g. from {@see bestFitDiagonals()} at derive time
     * and stored with {@see LatticeCodec::packBytes()}: stored corners alone cannot say which diagonal fits the real
     * surface. Cells outside the grid (the halo ring) keep the chessboard.
     *
     * @param list<float|null> $corners
     */
    public static function fromCorners(int $i0, int $j0, int $nx, int $nz, float $cell, array $corners, ?string $diagonals = null): self
    {
        $w = $nx + 1;
        if (count($corners) !== $w * ($nz + 1)) {
            throw new \InvalidArgumentException('HeightfieldGrid::fromCorners: expected ' . ($w * ($nz + 1)) . ' corners, got ' . count($corners));
        }
        if ($diagonals !== null && strlen($diagonals) !== $nx * $nz) {
            throw new \InvalidArgumentException('HeightfieldGrid::fromCorners: expected ' . ($nx * $nz) . ' diagonal bytes, got ' . strlen($diagonals));
        }
        $grid = self::sample(
            $i0 * $cell,
            $j0 * $cell,
            ($i0 + $nx) * $cell,
            ($j0 + $nz) * $cell,
            $cell,
            static function (float $x, float $z) use ($corners, $i0, $j0, $nx, $nz, $w, $cell): ?float {
                $i = (int) round($x / $cell) - $i0;
                $j = (int) round($z / $cell) - $j0;
                if ($i < 0 || $j < 0 || $i > $nx || $j > $nz) {
                    return null;
                }

                return $corners[$j * $w + $i];
            },
        );
        if ($diagonals === null) {
            return $grid;
        }
        $width = $nx + 3;
        $all = '';
        for ($cj = 0; $cj < $nz + 3; $cj++) {
            for ($ci = 0; $ci < $width; $ci++) {
                $i = $ci - 1;
                $j = $cj - 1;
                $all .= ($i >= 0 && $j >= 0 && $i < $nx && $j < $nz)
                    ? $diagonals[$j * $nx + $i]
                    : (self::diagonalRising($i0 + $i, $j0 + $j) ? "\x01" : "\x00");
            }
        }

        return new self($grid->i0, $grid->j0, $grid->nx, $grid->nz, $cell, $grid->heights, $grid->ground, $all);
    }

    /**
     * The best-fit diagonal of every cell of a grid with the given bounds, from the REAL height function, as one byte per
     * cell, row-major from the grid's first cell ("\x01" = from (i, j) to (i + 1, j + 1)): for the derive step of a stored
     * lattice, to be stored beside the corners and handed to {@see fromCorners()}. The rule is the one of
     * {@see sample()} with `$bestFitDiagonal` (the diagonal whose centre height is closer to the function at the cell
     * centre; ties and cells without ground follow the chessboard). Needs the function at the corners and centres.
     *
     * @param callable(float, float): (float|null) $height
     */
    public static function bestFitDiagonals(float $minX, float $minZ, float $maxX, float $maxZ, float $cell, callable $height): string
    {
        $i0 = (int) floor($minX / $cell + 1e-9);
        $j0 = (int) floor($minZ / $cell + 1e-9);
        $nx = max(1, (int) ceil($maxX / $cell - 1e-9) - $i0);
        $nz = max(1, (int) ceil($maxZ / $cell - 1e-9) - $j0);
        $out = '';
        for ($j = $j0; $j < $j0 + $nz; $j++) {
            for ($i = $i0; $i < $i0 + $nx; $i++) {
                $rising = self::diagonalRising($i, $j);
                $h00 = $height($i * $cell, $j * $cell);
                $h10 = $height(($i + 1) * $cell, $j * $cell);
                $h01 = $height($i * $cell, ($j + 1) * $cell);
                $h11 = $height(($i + 1) * $cell, ($j + 1) * $cell);
                $truth = $h00 === null || $h10 === null || $h01 === null || $h11 === null ? null : $height(($i + 0.5) * $cell, ($j + 0.5) * $cell);
                if ($truth !== null) {
                    $e1 = abs(($h00 + $h11) * 0.5 - $truth);
                    $e2 = abs(($h10 + $h01) * 0.5 - $truth);
                    if (abs($e1 - $e2) > 1.0e-9) {
                        $rising = $e1 < $e2;
                    }
                }
                $out .= $rising ? "\x01" : "\x00";
            }
        }

        return $out;
    }

    /**
     * The best-fit diagonal of every cell from (i0 - 1, j0 - 1) to (i0 + nx, j0 + nz): the one whose centre height
     * (mean of its two end corners) lies closer to the function at the cell centre; a tie, a cell without ground
     * or a cell outside the function's ground keeps the chessboard.
     *
     * @param list<float> $heights
     * @param callable(float, float): (float|null) $height
     */
    private static function fitDiagonals(int $i0, int $j0, int $nx, int $nz, float $cell, array $heights, ?string $mask, callable $height): string
    {
        $width = $nx + 3;
        $out = '';
        for ($cj = 0; $cj < $nz + 3; $cj++) {
            for ($ci = 0; $ci < $width; $ci++) {
                $i = $i0 - 1 + $ci;
                $j = $j0 - 1 + $cj;
                $rising = self::diagonalRising($i, $j);
                if ($ci < $width - 1 && $cj < $nz + 2) {
                    $k00 = $cj * $width + $ci;
                    $k11 = $k00 + $width + 1;
                    $k10 = $k00 + 1;
                    $k01 = $k00 + $width;
                    $ground = $mask === null || ($mask[$k00] === "\x01" && $mask[$k10] === "\x01" && $mask[$k01] === "\x01" && $mask[$k11] === "\x01");
                    $truth = $ground ? $height(($i + 0.5) * $cell, ($j + 0.5) * $cell) : null;
                    if ($truth !== null) {
                        $e1 = abs(($heights[$k00] + $heights[$k11]) * 0.5 - $truth);
                        $e2 = abs(($heights[$k10] + $heights[$k01]) * 0.5 - $truth);
                        if (abs($e1 - $e2) > 1.0e-9) {
                            $rising = $e1 < $e2;
                        }
                    }
                }
                $out .= $rising ? "\x01" : "\x00";
            }
        }

        return $out;
    }

    /** World X of the first / last corner column. */
    public function minX(): float
    {
        return $this->i0 * $this->cell;
    }

    public function maxX(): float
    {
        return ($this->i0 + $this->nx) * $this->cell;
    }

    public function minZ(): float
    {
        return $this->j0 * $this->cell;
    }

    public function maxZ(): float
    {
        return ($this->j0 + $this->nz) * $this->cell;
    }

    /** Height of the lattice corner (absolute indices; one corner of halo around the grid is valid). A groundless corner gives its fill. */
    public function cornerHeight(int $i, int $j): float
    {
        return $this->heights[($j - $this->j0 + 1) * ($this->nx + 3) + ($i - $this->i0 + 1)];
    }

    /** Whether the grid carries a ground mask. */
    public function isMasked(): bool
    {
        return $this->ground !== null;
    }

    /** Whether corner (i, j) has ground (always true on a grid without a mask). */
    public function hasGround(int $i, int $j): bool
    {
        return $this->ground === null || $this->ground[($j - $this->j0 + 1) * ($this->nx + 3) + ($i - $this->i0 + 1)] === "\x01";
    }

    /**
     * Whether triangle `$which` (0 or 1) of cell (i, j) exists: all three corners have ground.
     */
    public function triangleHasGround(int $i, int $j, int $which): bool
    {
        if ($this->ground === null) {
            return true;
        }
        foreach (self::triangleCorners($i, $j, $which, $this->risingAt($i, $j)) as [$ci, $cj]) {
            if (!$this->hasGround($ci, $cj)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the cell with lower corner (i, j) runs its diagonal from corner (i, j) to (i + 1, j + 1)
     * (true) or from (i + 1, j) to (i, j + 1).
     */
    public static function diagonalRising(int $i, int $j): bool
    {
        return (($i + $j) & 1) === 0;
    }

    /**
     * Whether the cell with lower corner (i, j) of THIS grid runs its diagonal from (i, j) to (i + 1, j + 1). Without
     * best-fit diagonals that is the chessboard ({@see diagonalRising()}). With them ({@see sample()}) each cell keeps the
     * diagonal whose triangulation lies closer to the real surface at the cell centre, so a crease that runs along a
     * diagonal is followed by the edge and not cut across by it (a kerb wave where a path conforms to the ground over a
     * crease). Stored per cell in the grid, so tiles of one cell size that sample the same function pick alike on a
     * shared border, and the mesh, {@see heightAt()} and {@see groundHeightAt()} all agree.
     */
    public function risingAt(int $i, int $j): bool
    {
        if ($this->diagonals === null) {
            return self::diagonalRising($i, $j);
        }

        return $this->diagonals[($j - $this->j0 + 1) * ($this->nx + 3) + ($i - $this->i0 + 1)] === "\x01";
    }

    /**
     * The three corners of triangle `$which` (0 or 1) of cell (i, j) as `[[i, j], ...]`, counter-clockwise
     * seen from above: the triangles of {@see HeightfieldGround}, in the same order. `$rising` = the cell's
     * diagonal ({@see risingAt()}); null = the chessboard.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function triangleCorners(int $i, int $j, int $which, ?bool $rising = null): array
    {
        $p00 = [$i, $j];
        $p10 = [$i + 1, $j];
        $p11 = [$i + 1, $j + 1];
        $p01 = [$i, $j + 1];
        if ($rising ?? self::diagonalRising($i, $j)) {
            return $which === 0 ? [$p00, $p01, $p11] : [$p00, $p11, $p10];
        }

        return $which === 0 ? [$p00, $p01, $p10] : [$p10, $p01, $p11];
    }

    /** Which triangle (0 or 1) of cell (i, j) the point (x, z) lies in (on the diagonal: the first). */
    public function triangleAt(int $i, int $j, float $x, float $z): int
    {
        $u = $x / $this->cell - $i;
        $v = $z / $this->cell - $j;
        if ($this->risingAt($i, $j)) {
            return $v >= $u ? 0 : 1;
        }

        return $u + $v <= 1.0 ? 0 : 1;
    }

    /**
     * Height of the triangulated surface at (x, z); clamped to the grid bounds. The mesh from
     * {@see HeightfieldGround::build()} lies on exactly this surface (a hole removes area, it moves
     * nothing). On a masked grid this is the surface through the FILL heights too; ask
     * {@see groundHeightAt()} where ground may be missing.
     */
    public function heightAt(float $x, float $z): float
    {
        $u = max((float) $this->i0, min((float) ($this->i0 + $this->nx), $x / $this->cell));
        $w = max((float) $this->j0, min((float) ($this->j0 + $this->nz), $z / $this->cell));
        $i = min($this->i0 + $this->nx - 1, (int) floor($u));
        $j = min($this->j0 + $this->nz - 1, (int) floor($w));
        $fx = $u - $i;
        $fz = $w - $j;
        $h00 = $this->cornerHeight($i, $j);
        $h10 = $this->cornerHeight($i + 1, $j);
        $h01 = $this->cornerHeight($i, $j + 1);
        $h11 = $this->cornerHeight($i + 1, $j + 1);
        if ($this->risingAt($i, $j)) {
            return $fx >= $fz
                ? $h00 + ($h10 - $h00) * $fx + ($h11 - $h10) * $fz
                : $h00 + ($h11 - $h01) * $fx + ($h01 - $h00) * $fz;
        }

        return $fx + $fz <= 1.0
            ? $h00 + ($h10 - $h00) * $fx + ($h01 - $h00) * $fz
            : $h11 + ($h01 - $h11) * (1.0 - $fx) + ($h10 - $h11) * (1.0 - $fz);
    }

    /**
     * Height of the ground at (x, z), null where the mesh has none: outside the grid, in a triangle with a
     * groundless corner, or (with `$holes`) strictly inside a hole (a point on the outline still has ground).
     * A point within a hair of a cell edge counts for the triangles on both sides, the highest hit wins, so a
     * query on a shared edge does not fall through.
     *
     * @param list<HeightfieldHole> $holes
     */
    public function groundHeightAt(float $x, float $z, array $holes = []): ?float
    {
        foreach ($holes as $hole) {
            if ($hole->signedDistance($x, $z) < 0.0) {
                return null;
            }
        }
        $c = $this->cell;
        $ia0 = max($this->i0, (int) floor(($x - self::EDGE_PAD) / $c));
        $ia1 = min($this->i0 + $this->nx - 1, (int) floor(($x + self::EDGE_PAD) / $c));
        $ja0 = max($this->j0, (int) floor(($z - self::EDGE_PAD) / $c));
        $ja1 = min($this->j0 + $this->nz - 1, (int) floor(($z + self::EDGE_PAD) / $c));
        $best = null;
        for ($ja = $ja0; $ja <= $ja1; $ja++) {
            for ($ia = $ia0; $ia <= $ia1; $ia++) {
                for ($which = 0; $which < 2; $which++) {
                    if (!$this->triangleHasGround($ia, $ja, $which)) {
                        continue;
                    }
                    [$a, $b, $d] = self::triangleCorners($ia, $ja, $which, $this->risingAt($ia, $ja));
                    $ax = $a[0] * $c;
                    $az = $a[1] * $c;
                    $bx = $b[0] * $c;
                    $bz = $b[1] * $c;
                    $dx = $d[0] * $c;
                    $dz = $d[1] * $c;
                    $den = ($bz - $dz) * ($ax - $dx) + ($dx - $bx) * ($az - $dz);
                    if (abs($den) < 1e-12) {
                        continue;
                    }
                    $w1 = (($bz - $dz) * ($x - $dx) + ($dx - $bx) * ($z - $dz)) / $den;
                    $w2 = (($dz - $az) * ($x - $dx) + ($ax - $dx) * ($z - $dz)) / $den;
                    $w3 = 1.0 - $w1 - $w2;
                    if ($w1 < -self::BARY_TOLERANCE || $w2 < -self::BARY_TOLERANCE || $w3 < -self::BARY_TOLERANCE) {
                        continue;
                    }
                    $y = $w1 * $this->cornerHeight($a[0], $a[1]) + $w2 * $this->cornerHeight($b[0], $b[1]) + $w3 * $this->cornerHeight($d[0], $d[1]);
                    if ($best === null || $y > $best) {
                        $best = $y;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Content hash of the lattice (xxh128 hex): format, origin, size, cell and every height as a
     * little-endian double, plus the ground mask when there is one (a grid without a mask hashes as before).
     */
    public function contentHash(): string
    {
        $h = hash_init('xxh128');
        hash_update($h, pack('VqqVVe', self::FORMAT, $this->i0, $this->j0, $this->nx, $this->nz, $this->cell));
        for ($i = 0, $n = count($this->heights); $i < $n; $i += 4096) {
            hash_update($h, pack('e*', ...array_slice($this->heights, $i, 4096)));
        }
        if ($this->ground !== null) {
            hash_update($h, 'ground');
            hash_update($h, $this->ground);
        }
        if ($this->diagonals !== null) {
            hash_update($h, 'diagonals');
            hash_update($h, $this->diagonals);
        }

        return hash_final($h);
    }
}
