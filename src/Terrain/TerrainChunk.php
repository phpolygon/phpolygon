<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

use PHPolygon\Geometry\MeshData;

/**
 * One chunk of a chunked terrain: its position in the chunk grid plus the mesh
 * built for that region.
 *
 * The chunk index is what makes a chunk's MeshRegistry id stable across
 * rebuilds ("<prefix>_c<x>_<z>"), which in turn lets the renderer invalidate
 * exactly the GPU buffers whose geometry changed instead of all of them.
 */
final readonly class TerrainChunk
{
    public function __construct(
        public int $chunkX,
        public int $chunkZ,
        public MeshData $mesh,
    ) {}

    /** Stable MeshRegistry id for this chunk under the given terrain prefix. */
    public function meshId(string $prefix): string
    {
        return "{$prefix}_c{$this->chunkX}_{$this->chunkZ}";
    }
}
