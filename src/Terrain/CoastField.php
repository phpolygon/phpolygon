<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

/**
 * The coast of an island or continent as a raster: where land lies, how far a
 * point is from the shoreline, and how high the terrain ends there.
 *
 * Imported terrain is often a shell of overlapping pieces, so a boundary curve
 * cannot be read off cleanly, and a shore with bays needs distances rather than
 * a curve anyway. The footprint is rasterised and the distances come from the
 * raster (8SSEDT, each cell keeps its nearest cell of the other side). Meant for
 * baking and for tests that measure a shore, not for per-frame use.
 *
 * Open water is what can be reached from the raster's edge. A hole INSIDE the
 * land (a crater, a sunken basin) does not count as sea.
 */
final class CoastField
{
    private const int LAND = 0;
    private const int SEA = 1;
    private const int HOLE = 2;

    /** @var array<int, int> cell kind by raster index */
    private array $kind = [];

    /** @var array<int, float> terrain height by land cell */
    private array $shell = [];

    /** @var array<int, int> nearest land cell for each sea or hole cell */
    private array $nearestLand = [];

    /** @var array<int, int> nearest sea cell for each land or hole cell */
    private array $nearestSea = [];

    private int $nx;
    private int $nz;

    /**
     * @param callable(float, float): ?float $ground terrain height at a world position, null = no terrain
     */
    public function __construct(
        callable $ground,
        public readonly float $minX,
        public readonly float $maxX,
        public readonly float $minZ,
        public readonly float $maxZ,
        public readonly float $cell,
    ) {
        $this->nx = (int) ceil(($maxX - $minX) / $cell) + 1;
        $this->nz = (int) ceil(($maxZ - $minZ) / $cell) + 1;
        $this->rasterize($ground);
        $this->markOpenSea();
        $this->nearestLand = $this->propagate(fn(int $kind): bool => $kind === self::LAND);
        $this->nearestSea = $this->propagate(fn(int $kind): bool => $kind === self::SEA);
    }

    /**
     * What the raster knows about a point, or null outside it.
     *
     * `distance` is signed: positive in open water, negative on land and in
     * holes (distance to the nearest open water). `rim` is the terrain height at
     * the nearest land cell, i.e. where the shell ends.
     *
     * @return array{distance: float, rim: ?float, hole: bool}|null
     */
    public function at(float $x, float $z): ?array
    {
        $ix = (int) round(($x - $this->minX) / $this->cell);
        $iz = (int) round(($z - $this->minZ) / $this->cell);
        if ($ix < 0 || $iz < 0 || $ix >= $this->nx || $iz >= $this->nz) {
            return null;
        }
        $i = $iz * $this->nx + $ix;
        $kind = $this->kind[$i];
        if ($kind === self::SEA) {
            $land = $this->nearestLand[$i] ?? -1;
            if ($land < 0) {
                return ['distance' => INF, 'rim' => null, 'hole' => false];
            }

            return ['distance' => $this->metres($i, $land, $x, $z), 'rim' => $this->shell[$land], 'hole' => false];
        }

        $sea = $this->nearestSea[$i] ?? -1;
        $distance = $sea < 0 ? -INF : -$this->metres($i, $sea, $x, $z);
        if ($kind === self::HOLE) {
            return ['distance' => $distance, 'rim' => null, 'hole' => true];
        }
        if ($sea >= 0) {
            // The rim height: the land cell closest to open water.
            $rim = $this->nearestLand[$sea] ?? $i;

            return ['distance' => $distance, 'rim' => $this->shell[$rim], 'hole' => false];
        }

        return ['distance' => $distance, 'rim' => $this->shell[$i], 'hole' => false];
    }

    /**
     * The shoreline: every land cell bordering open water, with its height and
     * the direction into the water.
     *
     * @return list<array{x: float, z: float, y: float, outX: float, outZ: float}>
     */
    public function rimPoints(): array
    {
        $points = [];
        foreach ($this->shell as $i => $y) {
            $ix = $i % $this->nx;
            $iz = intdiv($i, $this->nx);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
                $nx = $ix + $dx;
                $nz = $iz + $dz;
                if ($nx < 0 || $nz < 0 || $nx >= $this->nx || $nz >= $this->nz) {
                    continue;
                }
                if ($this->kind[$nz * $this->nx + $nx] === self::SEA) {
                    $points[] = [
                        'x' => $this->minX + $ix * $this->cell,
                        'z' => $this->minZ + $iz * $this->cell,
                        'y' => $y,
                        'outX' => (float) $dx,
                        'outZ' => (float) $dz,
                    ];
                    break;
                }
            }
        }

        return $points;
    }

    /** @param callable(float, float): ?float $ground */
    private function rasterize(callable $ground): void
    {
        for ($iz = 0; $iz < $this->nz; $iz++) {
            $z = $this->minZ + $iz * $this->cell;
            for ($ix = 0; $ix < $this->nx; $ix++) {
                $i = $iz * $this->nx + $ix;
                $y = $ground($this->minX + $ix * $this->cell, $z);
                $this->kind[$i] = $y === null ? self::HOLE : self::LAND;
                if ($y !== null) {
                    $this->shell[$i] = $y;
                }
            }
        }
    }

    /** Everything uncovered that can be reached from the raster edge becomes open water. */
    private function markOpenSea(): void
    {
        $stack = [];
        for ($ix = 0; $ix < $this->nx; $ix++) {
            $stack[] = $ix;
            $stack[] = ($this->nz - 1) * $this->nx + $ix;
        }
        for ($iz = 0; $iz < $this->nz; $iz++) {
            $stack[] = $iz * $this->nx;
            $stack[] = $iz * $this->nx + $this->nx - 1;
        }
        while ($stack !== []) {
            $i = array_pop($stack);
            if ($this->kind[$i] !== self::HOLE) {
                continue;
            }
            $this->kind[$i] = self::SEA;
            $ix = $i % $this->nx;
            if ($ix > 0) {
                $stack[] = $i - 1;
            }
            if ($ix < $this->nx - 1) {
                $stack[] = $i + 1;
            }
            if ($i >= $this->nx) {
                $stack[] = $i - $this->nx;
            }
            if ($i < count($this->kind) - $this->nx) {
                $stack[] = $i + $this->nx;
            }
        }
    }

    /**
     * For each cell the nearest cell matching $source (8SSEDT, two passes).
     *
     * @param \Closure(int): bool $source
     * @return array<int, int>
     */
    private function propagate(\Closure $source): array
    {
        $nearest = array_fill(0, $this->nx * $this->nz, -1);
        foreach ($this->kind as $i => $kind) {
            if ($source($kind)) {
                $nearest[$i] = $i;
            }
        }

        $relax = function (int $i, int $ix, int $iz, int $nb) use (&$nearest): void {
            $candidate = $nearest[$nb];
            if ($candidate < 0) {
                return;
            }
            $current = $nearest[$i];
            $cx = $candidate % $this->nx - $ix;
            $cz = intdiv($candidate, $this->nx) - $iz;
            if ($current < 0) {
                $nearest[$i] = $candidate;
                return;
            }
            $ox = $current % $this->nx - $ix;
            $oz = intdiv($current, $this->nx) - $iz;
            if ($cx * $cx + $cz * $cz < $ox * $ox + $oz * $oz) {
                $nearest[$i] = $candidate;
            }
        };

        $nx = $this->nx;
        for ($iz = 0; $iz < $this->nz; $iz++) {
            for ($ix = 0; $ix < $nx; $ix++) {
                $i = $iz * $nx + $ix;
                if ($iz > 0) {
                    $relax($i, $ix, $iz, $i - $nx);
                    if ($ix > 0) {
                        $relax($i, $ix, $iz, $i - $nx - 1);
                    }
                    if ($ix < $nx - 1) {
                        $relax($i, $ix, $iz, $i - $nx + 1);
                    }
                }
                if ($ix > 0) {
                    $relax($i, $ix, $iz, $i - 1);
                }
            }
            for ($ix = $nx - 2; $ix >= 0; $ix--) {
                $relax($iz * $nx + $ix, $ix, $iz, $iz * $nx + $ix + 1);
            }
        }
        for ($iz = $this->nz - 1; $iz >= 0; $iz--) {
            for ($ix = $nx - 1; $ix >= 0; $ix--) {
                $i = $iz * $nx + $ix;
                if ($iz < $this->nz - 1) {
                    $relax($i, $ix, $iz, $i + $nx);
                    if ($ix > 0) {
                        $relax($i, $ix, $iz, $i + $nx - 1);
                    }
                    if ($ix < $nx - 1) {
                        $relax($i, $ix, $iz, $i + $nx + 1);
                    }
                }
                if ($ix < $nx - 1) {
                    $relax($i, $ix, $iz, $i + 1);
                }
            }
            for ($ix = 1; $ix < $nx; $ix++) {
                $relax($iz * $nx + $ix, $ix, $iz, $iz * $nx + $ix - 1);
            }
        }

        return $nearest;
    }

    private function metres(int $i, int $to, float $x, float $z): float
    {
        return hypot(
            $this->minX + ($to % $this->nx) * $this->cell - $x,
            $this->minZ + intdiv($to, $this->nx) * $this->cell - $z,
        );
    }
}
