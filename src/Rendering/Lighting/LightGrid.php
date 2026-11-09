<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Lighting;

/**
 * World light grid: every point light of the frame in one storage buffer, and a
 * grid over the world's x/z plane whose cells list the lights that reach them. The
 * mesh fragment shader (PHPOLYGON_LIGHT_GRID) reads only the lights of its own
 * cell, so a city at night can light each street from its own lamps instead of
 * from the eight lamps nearest to the camera.
 *
 * A clustered layout in world space rather than in view space: street lamps stand
 * still, so the grid is rebuilt only when the set of lights changes, not every
 * time the camera moves. A light reaches the cells its radius overlaps (as a
 * square, generous by the corners); a cell keeps at most {@see MAX_PER_CELL}
 * lights, the strongest first.
 *
 * Buffers (std430, all uint/float, 4 bytes each):
 *   lights  2 vec4 per light: position.xyz + radius, colour·intensity.rgb + 0
 *   cells   a header of {@see HEADER} uints – nx, nz, origin x, origin z, 1/cell (float
 *           bits), light count, index count, 0 – then 2 uint per cell: offset into
 *           the index list, count. The shader takes the grid's shape from this
 *           header, never from a uniform, and bounds every index by it: on D3D12
 *           these are root UAVs without bounds checks, and a uniform left from
 *           another grid would read past the end and remove the device.
 *   indices uint light index per (cell, light)
 */
final class LightGrid
{
    /** Lights one cell keeps; the shader's loop bound. */
    public const int MAX_PER_CELL = 32;

    /** Largest grid side; the cell grows when the lights spread further. */
    public const int MAX_SIDE = 256;

    public readonly float $originX;
    public readonly float $originZ;
    public readonly float $cell;
    public readonly int $nx;
    public readonly int $nz;

    /** @var list<float> */
    public readonly array $lights;

    /** @var list<int> */
    public readonly array $cells;

    /** @var list<int> */
    public readonly array $indices;

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> $lights
     *        x, y, z, radius, r, g, b (colour already times intensity)
     */
    public function __construct(array $lights, float $cell = 16.0)
    {
        if ($cell <= 0.0) {
            throw new \InvalidArgumentException('LightGrid: cell must be positive');
        }
        $minX = $minZ = INF;
        $maxX = $maxZ = -INF;
        foreach ($lights as [$x, , $z, $r]) {
            $minX = min($minX, $x - $r);
            $maxX = max($maxX, $x + $r);
            $minZ = min($minZ, $z - $r);
            $maxZ = max($maxZ, $z + $r);
        }
        if ($lights === []) {
            $minX = $minZ = 0.0;
            $maxX = $maxZ = $cell;
        }
        $span = max($maxX - $minX, $maxZ - $minZ, $cell);
        $cell = max($cell, $span / self::MAX_SIDE);
        $this->cell = $cell;
        $this->originX = $minX;
        $this->originZ = $minZ;
        $this->nx = max(1, (int) ceil(($maxX - $minX) / $cell));
        $this->nz = max(1, (int) ceil(($maxZ - $minZ) / $cell));

        $flat = [];
        foreach ($lights as [$x, $y, $z, $r, $cr, $cg, $cb]) {
            array_push($flat, $x, $y, $z, $r, $cr, $cg, $cb, 0.0);
        }
        $this->lights = $flat;

        // Strongest first, so a full cell drops the faint ones.
        $order = array_keys($lights);
        $strength = static fn (int $i): float => ($lights[$i][4] + $lights[$i][5] + $lights[$i][6]) * $lights[$i][3];
        usort($order, static fn (int $a, int $b): int => $strength($b) <=> $strength($a) ?: $a <=> $b);

        $lists = [];
        foreach ($order as $i) {
            [$x, , $z, $r] = $lights[$i];
            $x0 = max(0, (int) floor(($x - $r - $minX) / $cell));
            $x1 = min($this->nx - 1, (int) floor(($x + $r - $minX) / $cell));
            $z0 = max(0, (int) floor(($z - $r - $minZ) / $cell));
            $z1 = min($this->nz - 1, (int) floor(($z + $r - $minZ) / $cell));
            for ($cz = $z0; $cz <= $z1; $cz++) {
                for ($cx = $x0; $cx <= $x1; $cx++) {
                    $c = $cx + $cz * $this->nx;
                    if (count($lists[$c] ?? []) < self::MAX_PER_CELL) {
                        $lists[$c][] = $i;
                    }
                }
            }
        }
        $cells = array_fill(0, 2 * $this->nx * $this->nz, 0);
        $indices = [];
        foreach ($lists as $c => $list) {
            $cells[2 * $c] = count($indices);
            $cells[2 * $c + 1] = count($list);
            array_push($indices, ...$list);
        }
        $this->cells = array_values($cells);
        // An empty list would be a zero-sized buffer.
        $this->indices = $indices === [] ? [0] : $indices;
    }

    /**
     * The lights a world point at (x, z) reads, as the shader does.
     *
     * @return list<int>
     */
    public function lightsAt(float $x, float $z): array
    {
        $cx = (int) floor(($x - $this->originX) / $this->cell);
        $cz = (int) floor(($z - $this->originZ) / $this->cell);
        if ($cx < 0 || $cz < 0 || $cx >= $this->nx || $cz >= $this->nz) {
            return [];
        }
        $c = $cx + $cz * $this->nx;

        return array_slice($this->indices, $this->cells[2 * $c], $this->cells[2 * $c + 1]);
    }

    /**
     * u_light_grid = (origin x, origin z, 1/cell, 1 = on).
     *
     * @return list<float>
     */
    public function gridUniform(): array
    {
        return [$this->originX, $this->originZ, 1.0 / $this->cell, 1.0];
    }

    /**
     * u_light_grid_dims = (nx, nz, 0, 0).
     *
     * @return list<float>
     */
    public function dimsUniform(): array
    {
        return [(float) $this->nx, (float) $this->nz, 0.0, 0.0];
    }

    public function lightBytes(): string
    {
        return pack('g*', ...$this->lights);
    }

    /** uints before the first cell in the cells buffer. */
    public const int HEADER = 8;

    public function cellBytes(): string
    {
        $f = static function (float $v): int {
            $u = unpack('V', pack('g', $v));

            return is_array($u) && is_int($u[1] ?? null) ? $u[1] : 0;
        };
        $header = [$this->nx, $this->nz, $f($this->originX), $f($this->originZ), $f(1.0 / $this->cell),
            intdiv(count($this->lights), 8), count($this->indices), 0];

        return pack('V*', ...$header, ...$this->cells);
    }

    /** The cells buffer of no grid: a header with zero cells. */
    public static function emptyCellBytes(): string
    {
        return pack('V*', ...array_fill(0, self::HEADER, 0));
    }

    public function indexBytes(): string
    {
        return pack('V*', ...$this->indices);
    }
}
