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
 */
final class HeightfieldGrid
{
    /** Bump when the sampling or the layout of the data changes. */
    public const int FORMAT = 1;

    /**
     * @param int $i0 lattice index of the first corner column (corner x = $i0 * $cell)
     * @param int $j0 lattice index of the first corner row (corner z = $j0 * $cell)
     * @param int $nx cells along X (corners: $nx + 1, plus the halo)
     * @param int $nz cells along Z
     * @param list<float> $heights (nx + 3) * (nz + 3) heights, row-major, starting one corner before ($i0, $j0)
     */
    public function __construct(
        public readonly int $i0,
        public readonly int $j0,
        public readonly int $nx,
        public readonly int $nz,
        public readonly float $cell,
        public readonly array $heights,
    ) {
        if ($cell <= 0.0 || $nx < 1 || $nz < 1) {
            throw new \InvalidArgumentException('HeightfieldGrid: needs cell > 0 and at least one cell');
        }
        if (count($heights) !== ($nx + 3) * ($nz + 3)) {
            throw new \InvalidArgumentException('HeightfieldGrid: expected ' . (($nx + 3) * ($nz + 3)) . ' heights, got ' . count($heights));
        }
    }

    /**
     * Sample $height over the bounds, grown to whole cells (the origin snaps to a multiple of $cell,
     * the same rule as {@see ConformingRegionMesh::covering()}). Calls $height once per corner,
     * halo included, in a fixed order.
     *
     * @param callable(float, float): float $height
     */
    public static function sample(float $minX, float $minZ, float $maxX, float $maxZ, float $cell, callable $height): self
    {
        if ($cell <= 0.0 || $maxX <= $minX || $maxZ <= $minZ) {
            throw new \InvalidArgumentException('HeightfieldGrid::sample: empty bounds or cell <= 0');
        }
        $i0 = (int) floor($minX / $cell + 1e-9);
        $j0 = (int) floor($minZ / $cell + 1e-9);
        $nx = max(1, (int) ceil($maxX / $cell - 1e-9) - $i0);
        $nz = max(1, (int) ceil($maxZ / $cell - 1e-9) - $j0);
        $heights = [];
        for ($j = $j0 - 1; $j <= $j0 + $nz + 1; $j++) {
            $z = $j * $cell;
            for ($i = $i0 - 1; $i <= $i0 + $nx + 1; $i++) {
                $heights[] = (float) $height($i * $cell, $z);
            }
        }

        return new self($i0, $j0, $nx, $nz, $cell, $heights);
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

    /** Height of the lattice corner (absolute indices; one corner of halo around the grid is valid). */
    public function cornerHeight(int $i, int $j): float
    {
        return $this->heights[($j - $this->j0 + 1) * ($this->nx + 3) + ($i - $this->i0 + 1)];
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
     * Height of the triangulated surface at (x, z); clamped to the grid bounds. The mesh from
     * {@see HeightfieldGround::build()} lies on exactly this surface (a hole removes area, it moves
     * nothing).
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
        if (self::diagonalRising($i, $j)) {
            return $fx >= $fz
                ? $h00 + ($h10 - $h00) * $fx + ($h11 - $h10) * $fz
                : $h00 + ($h11 - $h01) * $fx + ($h01 - $h00) * $fz;
        }

        return $fx + $fz <= 1.0
            ? $h00 + ($h10 - $h00) * $fx + ($h01 - $h00) * $fz
            : $h11 + ($h01 - $h11) * (1.0 - $fx) + ($h10 - $h11) * (1.0 - $fz);
    }

    /**
     * Content hash of the lattice (xxh128 hex): format, origin, size, cell and every height as a
     * little-endian double. Equal heights give the same hash on every platform.
     */
    public function contentHash(): string
    {
        $h = hash_init('xxh128');
        hash_update($h, pack('VqqVVe', self::FORMAT, $this->i0, $this->j0, $this->nx, $this->nz, $this->cell));
        for ($i = 0, $n = count($this->heights); $i < $n; $i += 4096) {
            hash_update($h, pack('e*', ...array_slice($this->heights, $i, 4096)));
        }

        return hash_final($h);
    }
}
