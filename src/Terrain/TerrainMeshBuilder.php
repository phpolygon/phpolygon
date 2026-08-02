<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

use InvalidArgumentException;
use PHPolygon\Geometry\MeshData;

/**
 * Turns a {@see HeightmapData} into renderable {@see MeshData}, either as one
 * mesh or split into a grid of chunks.
 *
 * Chunking exists because a single terrain mesh gets impractical fast — a
 * 257×257 grid is ~66k vertices and ~132k triangles in one draw call with one
 * bounding volume, so nothing can be frustum-culled and the whole thing is
 * re-uploaded whenever one vertex moves. Chunks make culling and incremental
 * re-upload possible.
 *
 * Chunks *share* their border vertices with their neighbours (chunk N ends at
 * the grid column where chunk N+1 begins) and take their normals from the
 * height field rather than from triangle faces, so chunk borders are seamless
 * in both position and shading.
 */
final class TerrainMeshBuilder
{
    /**
     * Build the whole heightmap as a single mesh.
     *
     * Fine for small terrains and for the editor preview; prefer
     * {@see buildChunks()} for anything large enough to want culling.
     */
    public function buildSingle(HeightmapData $heightmap): MeshData
    {
        return $this->buildRegion(
            $heightmap,
            0,
            0,
            $heightmap->gridWidth - 1,
            $heightmap->gridDepth - 1,
        );
    }

    /**
     * Split the heightmap into a grid of chunk meshes.
     *
     * @param  int  $chunkSize  Quads per chunk edge. Clamped to at least 1 and
     *                          to the grid size; it does not have to divide the
     *                          grid evenly — the last row/column of chunks is
     *                          simply smaller.
     * @return list<TerrainChunk> Row-major (z-major), matching heightmap layout.
     */
    public function buildChunks(HeightmapData $heightmap, int $chunkSize = 32): array
    {
        $quadsX = $heightmap->gridWidth - 1;
        $quadsZ = $heightmap->gridDepth - 1;
        $chunkSize = max(1, min($chunkSize, max($quadsX, $quadsZ)));

        $chunksX = (int) ceil($quadsX / $chunkSize);
        $chunksZ = (int) ceil($quadsZ / $chunkSize);

        $chunks = [];
        for ($cz = 0; $cz < $chunksZ; $cz++) {
            for ($cx = 0; $cx < $chunksX; $cx++) {
                $x0 = $cx * $chunkSize;
                $z0 = $cz * $chunkSize;
                // Inclusive end vertex — deliberately the *same* grid line the
                // next chunk starts on, so the shared edge has identical
                // vertices on both sides and cannot crack.
                $x1 = min($x0 + $chunkSize, $quadsX);
                $z1 = min($z0 + $chunkSize, $quadsZ);

                $chunks[] = new TerrainChunk(
                    $cx,
                    $cz,
                    $this->buildRegion($heightmap, $x0, $z0, $x1, $z1),
                );
            }
        }

        return $chunks;
    }

    /**
     * Build the sub-rectangle of the grid spanning grid columns [$x0, $x1] and
     * rows [$z0, $z1] (both inclusive, in vertex coordinates).
     *
     * Vertex positions stay in the terrain's own space — the region is *not*
     * re-centred on its own origin — so every chunk mesh can be rendered with
     * the terrain entity's transform and needs no per-chunk offset.
     */
    public function buildRegion(HeightmapData $heightmap, int $x0, int $z0, int $x1, int $z1): MeshData
    {
        if ($x1 <= $x0 || $z1 <= $z0) {
            throw new InvalidArgumentException("Terrain region must span at least one quad, got [{$x0},{$z0}]..[{$x1},{$z1}]");
        }

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        $cols = $x1 - $x0 + 1;

        for ($z = $z0; $z <= $z1; $z++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $vertices[] = $heightmap->worldX($x);
                $vertices[] = $heightmap->heightAt($x, $z);
                $vertices[] = $heightmap->worldZ($z);

                [$nx, $ny, $nz] = $heightmap->normalAt($x, $z);
                $normals[] = $nx;
                $normals[] = $ny;
                $normals[] = $nz;

                // UVs are global across the whole terrain (0..1 over the full
                // grid), not per-chunk. Splat maps and any terrain-wide texture
                // therefore line up across chunk borders for free; per-layer
                // tiling is a material-side uv scale.
                // Cast explicitly: PHP's `/` yields int for exact int division,
                // which would leave a mixed int/float buffer for the GPU upload.
                $uvs[] = (float) ($x / ($heightmap->gridWidth - 1));
                $uvs[] = (float) ($z / ($heightmap->gridDepth - 1));
            }
        }

        for ($z = 0; $z < $z1 - $z0; $z++) {
            for ($x = 0; $x < $x1 - $x0; $x++) {
                $topLeft = $z * $cols + $x;
                $topRight = $topLeft + 1;
                $bottomLeft = $topLeft + $cols;
                $bottomRight = $bottomLeft + 1;

                // Counter-clockwise when viewed from +Y, matching the engine's
                // front-face winding for upward-facing surfaces.
                $indices[] = $topLeft;
                $indices[] = $bottomLeft;
                $indices[] = $topRight;

                $indices[] = $topRight;
                $indices[] = $bottomLeft;
                $indices[] = $bottomRight;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
