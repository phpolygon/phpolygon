<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec3;

/**
 * Uniform grid spatial hash for broadphase collision detection.
 * Inserts AABBs into grid cells, then returns unique pairs of entities sharing cells.
 */
class SpatialHash3D
{
    /** @var array<string, list<int>> Cell key → entity IDs */
    private array $cells = [];

    public function __construct(
        private readonly float $cellSize = 2.0,
    ) {}

    public function clear(): void
    {
        $this->cells = [];
    }

    public function insert(int $entityId, Vec3 $min, Vec3 $max): void
    {
        $invCell = 1.0 / $this->cellSize;
        $x0 = (int) floor($min->x * $invCell);
        $y0 = (int) floor($min->y * $invCell);
        $z0 = (int) floor($min->z * $invCell);
        $x1 = (int) floor($max->x * $invCell);
        $y1 = (int) floor($max->y * $invCell);
        $z1 = (int) floor($max->z * $invCell);

        for ($x = $x0; $x <= $x1; $x++) {
            for ($y = $y0; $y <= $y1; $y++) {
                for ($z = $z0; $z <= $z1; $z++) {
                    $key = "{$x}_{$y}_{$z}";
                    $this->cells[$key][] = $entityId;
                }
            }
        }
    }

    /**
     * Returns unique pairs of entity IDs that share at least one cell.
     *
     * @return list<array{int, int}>
     */
    public function queryPairs(): array
    {
        $seen = [];
        $pairs = [];

        foreach ($this->cells as $ids) {
            $count = count($ids);
            if ($count < 2) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = min($ids[$i], $ids[$j]);
                    $b = max($ids[$i], $ids[$j]);
                    $key = "{$a}_{$b}";
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $pairs[] = [$a, $b];
                    }
                }
            }
        }

        return $pairs;
    }
}
