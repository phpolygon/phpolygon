<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Shadow;

/**
 * Uniform grid over the bounding spheres of a frame's shadow casters, so a light's cube
 * only looks at the casters near it instead of all of them. Pure PHP, no GPU.
 *
 * A caster is registered with its index and bounding sphere; one larger than a few cells
 * (ground, a long wall) goes on a short list that every query tests. {@see query()}
 * returns the indices of every caster whose sphere touches the query sphere, ascending
 * (a superset of the casters that can shade it, never a subset).
 */
final class ShadowCasterGrid
{
    /** A sphere wider than this many cells is kept on the global list. */
    private const MAX_CELLS_PER_AXIS = 4;

    /** @var array<string, list<int>> */
    private array $cells = [];
    /** @var list<int> */
    private array $large = [];
    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> */
    private array $spheres = [];

    public function __construct(private readonly float $cellSize = 8.0)
    {
    }

    public function count(): int
    {
        return count($this->spheres);
    }

    public function add(int $index, float $x, float $y, float $z, float $radius): void
    {
        $this->spheres[$index] = [$x, $y, $z, $radius];
        if (!is_finite($x + $y + $z + $radius)) {
            $this->large[] = $index;

            return;
        }
        $inv = 1.0 / $this->cellSize;
        $x0 = (int) floor(($x - $radius) * $inv);
        $x1 = (int) floor(($x + $radius) * $inv);
        $y0 = (int) floor(($y - $radius) * $inv);
        $y1 = (int) floor(($y + $radius) * $inv);
        $z0 = (int) floor(($z - $radius) * $inv);
        $z1 = (int) floor(($z + $radius) * $inv);
        if ($x1 - $x0 >= self::MAX_CELLS_PER_AXIS || $y1 - $y0 >= self::MAX_CELLS_PER_AXIS || $z1 - $z0 >= self::MAX_CELLS_PER_AXIS) {
            $this->large[] = $index;

            return;
        }
        for ($cx = $x0; $cx <= $x1; $cx++) {
            for ($cy = $y0; $cy <= $y1; $cy++) {
                for ($cz = $z0; $cz <= $z1; $cz++) {
                    $this->cells["$cx,$cy,$cz"][] = $index;
                }
            }
        }
    }

    /** @return list<int> */
    public function query(float $x, float $y, float $z, float $radius): array
    {
        $inv = 1.0 / $this->cellSize;
        $x0 = (int) floor(($x - $radius) * $inv);
        $x1 = (int) floor(($x + $radius) * $inv);
        $y0 = (int) floor(($y - $radius) * $inv);
        $y1 = (int) floor(($y + $radius) * $inv);
        $z0 = (int) floor(($z - $radius) * $inv);
        $z1 = (int) floor(($z + $radius) * $inv);
        $found = [];
        for ($cx = $x0; $cx <= $x1; $cx++) {
            for ($cy = $y0; $cy <= $y1; $cy++) {
                for ($cz = $z0; $cz <= $z1; $cz++) {
                    foreach ($this->cells["$cx,$cy,$cz"] ?? [] as $i) {
                        $found[$i] = true;
                    }
                }
            }
        }
        foreach ($this->large as $i) {
            $found[$i] = true;
        }
        $out = [];
        foreach ($found as $i => $_) {
            [$sx, $sy, $sz, $sr] = $this->spheres[$i];
            $dx = $x - $sx;
            $dy = $y - $sy;
            $dz = $z - $sz;
            $reach = $radius + $sr;
            // Written so that a NaN keeps the caster.
            if (!($dx * $dx + $dy * $dy + $dz * $dz > $reach * $reach)) {
                $out[] = $i;
            }
        }
        sort($out);

        return $out;
    }
}
