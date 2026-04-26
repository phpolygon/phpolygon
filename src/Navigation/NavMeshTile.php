<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Triangle;

/**
 * A single tile of a tiled NavMesh.
 *
 * Each tile covers a rectangular world-space region and contains
 * its own set of polygons. Tiles are linked at boundaries via
 * cross-tile edges managed by TiledNavMesh.
 */
class NavMeshTile
{
    public readonly int $tileX;
    public readonly int $tileZ;
    public readonly float $worldMinX;
    public readonly float $worldMinZ;
    public readonly float $worldMaxX;
    public readonly float $worldMaxZ;

    private ?NavMesh $navMesh = null;
    private bool $dirty = true;

    public function __construct(
        int $tileX,
        int $tileZ,
        float $worldMinX,
        float $worldMinZ,
        float $worldMaxX,
        float $worldMaxZ,
    ) {
        $this->tileX = $tileX;
        $this->tileZ = $tileZ;
        $this->worldMinX = $worldMinX;
        $this->worldMinZ = $worldMinZ;
        $this->worldMaxX = $worldMaxX;
        $this->worldMaxZ = $worldMaxZ;
    }

    public function getNavMesh(): ?NavMesh
    {
        return $this->navMesh;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function markDirty(): void
    {
        $this->dirty = true;
    }

    /**
     * Rebuild this tile's NavMesh from triangles.
     *
     * @param Triangle[] $triangles World-space triangles within this tile's bounds.
     */
    public function rebuild(array $triangles, NavMeshGeneratorConfig $config): void
    {
        $generator = new NavMeshGenerator($config);
        $this->navMesh = $generator->generate($triangles);
        $this->dirty = false;
    }

    /**
     * Check if a world position is within this tile's XZ bounds.
     */
    public function containsXZ(Vec3 $position): bool
    {
        return $position->x >= $this->worldMinX
            && $position->x <= $this->worldMaxX
            && $position->z >= $this->worldMinZ
            && $position->z <= $this->worldMaxZ;
    }

    /**
     * Filter triangles that overlap this tile's XZ bounds.
     *
     * @param Triangle[] $allTriangles
     * @return Triangle[]
     */
    public function filterTriangles(array $allTriangles): array
    {
        $result = [];
        foreach ($allTriangles as $tri) {
            if ($this->triangleOverlaps($tri)) {
                $result[] = $tri;
            }
        }
        return $result;
    }

    private function triangleOverlaps(Triangle $tri): bool
    {
        $triMinX = min($tri->v0->x, $tri->v1->x, $tri->v2->x);
        $triMaxX = max($tri->v0->x, $tri->v1->x, $tri->v2->x);
        $triMinZ = min($tri->v0->z, $tri->v1->z, $tri->v2->z);
        $triMaxZ = max($tri->v0->z, $tri->v1->z, $tri->v2->z);

        return $triMaxX >= $this->worldMinX
            && $triMinX <= $this->worldMaxX
            && $triMaxZ >= $this->worldMinZ
            && $triMinZ <= $this->worldMaxZ;
    }

    /**
     * @return array{tileX: int, tileZ: int, worldMinX: float, worldMinZ: float, worldMaxX: float, worldMaxZ: float, navMesh: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'tileX' => $this->tileX,
            'tileZ' => $this->tileZ,
            'worldMinX' => $this->worldMinX,
            'worldMinZ' => $this->worldMinZ,
            'worldMaxX' => $this->worldMaxX,
            'worldMaxZ' => $this->worldMaxZ,
            'navMesh' => $this->navMesh?->toArray(),
        ];
    }
}
