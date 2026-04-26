<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Triangle;

/**
 * Manages a grid of NavMeshTiles for large worlds.
 *
 * Supports incremental tile rebuilding - only tiles marked dirty
 * are regenerated, making it practical for large or dynamic worlds.
 * Cross-tile pathfinding uses A* across tile boundaries.
 */
class TiledNavMesh
{
    /** @var array<string, NavMeshTile> Key: "{tileX}_{tileZ}" */
    private array $tiles = [];

    private float $tileSize;
    private NavMeshGeneratorConfig $config;

    public function __construct(float $tileSize = 32.0, ?NavMeshGeneratorConfig $config = null)
    {
        $this->tileSize = $tileSize;
        $this->config = $config ?? new NavMeshGeneratorConfig();
    }

    public function getTileSize(): float
    {
        return $this->tileSize;
    }

    /**
     * Get or create the tile at the given tile coordinates.
     */
    public function getTile(int $tileX, int $tileZ): NavMeshTile
    {
        $key = "{$tileX}_{$tileZ}";

        if (!isset($this->tiles[$key])) {
            $this->tiles[$key] = new NavMeshTile(
                $tileX,
                $tileZ,
                $tileX * $this->tileSize,
                $tileZ * $this->tileSize,
                ($tileX + 1) * $this->tileSize,
                ($tileZ + 1) * $this->tileSize,
            );
        }

        return $this->tiles[$key];
    }

    /**
     * Get the tile containing a world position.
     */
    public function getTileAt(float $worldX, float $worldZ): NavMeshTile
    {
        $tx = (int) floor($worldX / $this->tileSize);
        $tz = (int) floor($worldZ / $this->tileSize);
        return $this->getTile($tx, $tz);
    }

    /**
     * Mark a specific tile for rebuild.
     */
    public function invalidateTile(int $tileX, int $tileZ): void
    {
        $tile = $this->getTile($tileX, $tileZ);
        $tile->markDirty();
    }

    /**
     * Mark all tiles that overlap a world-space region for rebuild.
     */
    public function invalidateRegion(float $minX, float $minZ, float $maxX, float $maxZ): void
    {
        $tx0 = (int) floor($minX / $this->tileSize);
        $tz0 = (int) floor($minZ / $this->tileSize);
        $tx1 = (int) floor($maxX / $this->tileSize);
        $tz1 = (int) floor($maxZ / $this->tileSize);

        for ($tx = $tx0; $tx <= $tx1; $tx++) {
            for ($tz = $tz0; $tz <= $tz1; $tz++) {
                $this->invalidateTile($tx, $tz);
            }
        }
    }

    /**
     * Rebuild all dirty tiles from the given world triangles.
     *
     * @param Triangle[] $allTriangles All world-space triangles.
     * @return int Number of tiles rebuilt.
     */
    public function rebuildDirtyTiles(array $allTriangles): int
    {
        $rebuilt = 0;

        foreach ($this->tiles as $tile) {
            if (!$tile->isDirty()) {
                continue;
            }

            $tileTriangles = $tile->filterTriangles($allTriangles);
            $tile->rebuild($tileTriangles, $this->config);
            $rebuilt++;
        }

        return $rebuilt;
    }

    /**
     * Rebuild a specific tile from triangles.
     *
     * @param Triangle[] $triangles World-space triangles for this tile.
     */
    public function rebuildTile(int $tileX, int $tileZ, array $triangles): void
    {
        $tile = $this->getTile($tileX, $tileZ);
        $tileTriangles = $tile->filterTriangles($triangles);
        $tile->rebuild($tileTriangles, $this->config);
    }

    /**
     * Find the polygon at a world position across all tiles.
     */
    public function findPolygonAt(Vec3 $position): ?NavMeshPolygon
    {
        $tile = $this->getTileAt($position->x, $position->z);
        $navMesh = $tile->getNavMesh();

        if ($navMesh !== null) {
            $poly = $navMesh->findPolygonAt($position);
            if ($poly !== null) {
                return $poly;
            }
        }

        // Check neighboring tiles (position might be on the edge)
        $tx = (int) floor($position->x / $this->tileSize);
        $tz = (int) floor($position->z / $this->tileSize);

        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dz = -1; $dz <= 1; $dz++) {
                if ($dx === 0 && $dz === 0) {
                    continue;
                }

                $key = ($tx + $dx) . '_' . ($tz + $dz);
                if (!isset($this->tiles[$key])) {
                    continue;
                }

                $neighborMesh = $this->tiles[$key]->getNavMesh();
                if ($neighborMesh === null) {
                    continue;
                }

                $poly = $neighborMesh->findPolygonAt($position);
                if ($poly !== null) {
                    return $poly;
                }
            }
        }

        return null;
    }

    /**
     * Get all loaded tiles.
     *
     * @return NavMeshTile[]
     */
    public function getTiles(): array
    {
        return array_values($this->tiles);
    }

    public function tileCount(): int
    {
        return count($this->tiles);
    }

    /**
     * Serialize tile data for thread transfer.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $data = [];
        foreach ($this->tiles as $key => $tile) {
            $data[$key] = $tile->toArray();
        }
        return $data;
    }
}
