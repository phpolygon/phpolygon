<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Triangle;

/**
 * Generates a NavMesh from world-space triangles.
 *
 * Simplified Recast-style pipeline:
 * 1. Filter walkable triangles by slope angle
 * 2. Rasterize into a 2D heightfield (XZ grid)
 * 3. Mark walkable spans (agent clearance check)
 * 4. Build regions via flood-fill
 * 5. Convert regions to convex polygons
 * 6. Build NavMesh with adjacency
 */
class NavMeshGenerator
{
    private NavMeshGeneratorConfig $config;

    public function __construct(?NavMeshGeneratorConfig $config = null)
    {
        $this->config = $config ?? new NavMeshGeneratorConfig();
    }

    /**
     * Generate a NavMesh from world-space triangles.
     *
     * @param Triangle[] $triangles
     */
    public function generate(array $triangles): NavMesh
    {
        // Step 1: Filter walkable triangles
        $walkable = $this->filterWalkable($triangles);

        if (count($walkable) === 0) {
            return new NavMesh();
        }

        // Step 2: Compute world bounds
        $bounds = $this->computeBounds($walkable);

        // Step 3: Rasterize into heightfield
        $heightfield = $this->rasterize($walkable, $bounds);

        // Step 4: Build regions via flood-fill
        $regions = $this->buildRegions($heightfield, $bounds);

        // Step 5: Convert regions to NavMesh polygons
        return $this->buildNavMesh($regions, $heightfield, $bounds);
    }

    /**
     * Filter triangles by walkable slope.
     *
     * @param Triangle[] $triangles
     * @return Triangle[]
     */
    private function filterWalkable(array $triangles): array
    {
        $up = new Vec3(0.0, 1.0, 0.0);
        $maxCos = cos(deg2rad($this->config->agentMaxSlope));
        $walkable = [];

        foreach ($triangles as $tri) {
            if ($tri->isDegenerate()) {
                continue;
            }

            // Dot product with up vector = cosine of slope angle.
            // Use absolute value to handle both winding orders.
            $dot = abs($tri->normal->dot($up));
            if ($dot >= $maxCos) {
                $walkable[] = $tri;
            }
        }

        return $walkable;
    }

    /**
     * @param Triangle[] $triangles
     * @return array{minX: float, minZ: float, maxX: float, maxZ: float, minY: float, maxY: float}
     */
    private function computeBounds(array $triangles): array
    {
        $minX = $minZ = $minY = PHP_FLOAT_MAX;
        $maxX = $maxZ = $maxY = -PHP_FLOAT_MAX;

        foreach ($triangles as $tri) {
            foreach ([$tri->v0, $tri->v1, $tri->v2] as $v) {
                if ($v->x < $minX) $minX = $v->x;
                if ($v->x > $maxX) $maxX = $v->x;
                if ($v->y < $minY) $minY = $v->y;
                if ($v->y > $maxY) $maxY = $v->y;
                if ($v->z < $minZ) $minZ = $v->z;
                if ($v->z > $maxZ) $maxZ = $v->z;
            }
        }

        return compact('minX', 'minZ', 'maxX', 'maxZ', 'minY', 'maxY');
    }

    /**
     * Rasterize walkable triangles into a 2D heightfield grid.
     *
     * Each cell stores the walkable surface height (Y). Cells with
     * no walkable surface store null.
     *
     * @param Triangle[] $triangles
     * @param array{minX: float, minZ: float, maxX: float, maxZ: float, minY: float, maxY: float} $bounds
     * @return array{width: int, depth: int, cells: array<int, float>}
     */
    private function rasterize(array $triangles, array $bounds): array
    {
        $cs = $this->config->cellSize;
        $width = max(1, (int) ceil(($bounds['maxX'] - $bounds['minX']) / $cs));
        $depth = max(1, (int) ceil(($bounds['maxZ'] - $bounds['minZ']) / $cs));

        /** @var array<int, float> Cell index -> height (highest walkable Y) */
        $cells = [];

        foreach ($triangles as $tri) {
            $this->rasterizeTriangle($tri, $bounds, $width, $depth, $cs, $cells);
        }

        return ['width' => $width, 'depth' => $depth, 'cells' => $cells];
    }

    /**
     * Rasterize a single triangle into the heightfield grid.
     *
     * @param array{minX: float, minZ: float, maxX: float, maxZ: float, minY: float, maxY: float} $bounds
     * @param array<int, float> $cells
     */
    private function rasterizeTriangle(
        Triangle $tri,
        array $bounds,
        int $width,
        int $depth,
        float $cs,
        array &$cells,
    ): void {
        // Triangle AABB in grid space
        $minGX = max(0, (int) floor(($this->triMinX($tri) - $bounds['minX']) / $cs));
        $maxGX = min($width - 1, (int) floor(($this->triMaxX($tri) - $bounds['minX']) / $cs));
        $minGZ = max(0, (int) floor(($this->triMinZ($tri) - $bounds['minZ']) / $cs));
        $maxGZ = min($depth - 1, (int) floor(($this->triMaxZ($tri) - $bounds['minZ']) / $cs));

        for ($gz = $minGZ; $gz <= $maxGZ; $gz++) {
            for ($gx = $minGX; $gx <= $maxGX; $gx++) {
                // Cell center in world space
                $wx = $bounds['minX'] + ($gx + 0.5) * $cs;
                $wz = $bounds['minZ'] + ($gz + 0.5) * $cs;

                // Interpolate Y from triangle at this XZ
                $y = $this->interpolateTriangleY($tri, $wx, $wz);
                if ($y === null) {
                    continue;
                }

                $idx = $gz * $width + $gx;
                if (!isset($cells[$idx]) || $y > $cells[$idx]) {
                    $cells[$idx] = $y;
                }
            }
        }
    }

    /**
     * Interpolate the Y coordinate on a triangle at a given (X, Z).
     * Returns null if the point is outside the triangle (XZ projection).
     */
    private function interpolateTriangleY(Triangle $tri, float $x, float $z): ?float
    {
        // Barycentric coordinates in XZ
        $v0x = $tri->v2->x - $tri->v0->x;
        $v0z = $tri->v2->z - $tri->v0->z;
        $v1x = $tri->v1->x - $tri->v0->x;
        $v1z = $tri->v1->z - $tri->v0->z;
        $v2x = $x - $tri->v0->x;
        $v2z = $z - $tri->v0->z;

        $dot00 = $v0x * $v0x + $v0z * $v0z;
        $dot01 = $v0x * $v1x + $v0z * $v1z;
        $dot02 = $v0x * $v2x + $v0z * $v2z;
        $dot11 = $v1x * $v1x + $v1z * $v1z;
        $dot12 = $v1x * $v2x + $v1z * $v2z;

        $denom = $dot00 * $dot11 - $dot01 * $dot01;
        if (abs($denom) < 1e-10) {
            return null;
        }

        $invDenom = 1.0 / $denom;
        $u = ($dot11 * $dot02 - $dot01 * $dot12) * $invDenom;
        $v = ($dot00 * $dot12 - $dot01 * $dot02) * $invDenom;

        if ($u < -1e-6 || $v < -1e-6 || ($u + $v) > 1.0 + 1e-6) {
            return null;
        }

        // Interpolate Y
        return $tri->v0->y + $u * ($tri->v2->y - $tri->v0->y) + $v * ($tri->v1->y - $tri->v0->y);
    }

    /**
     * Build connected regions via flood-fill on the heightfield.
     *
     * Adjacent cells are connected if their height difference is within
     * agentMaxClimb. Each region gets a unique ID.
     *
     * @param array{width: int, depth: int, cells: array<int, float>} $heightfield
     * @param array{minX: float, minZ: float, maxX: float, maxZ: float, minY: float, maxY: float} $bounds
     * @return array<int, int> Cell index -> region ID
     */
    private function buildRegions(array $heightfield, array $bounds): array
    {
        $width = $heightfield['width'];
        $depth = $heightfield['depth'];
        $cells = $heightfield['cells'];
        $maxClimb = $this->config->agentMaxClimb;

        /** @var array<int, int> */
        $regionMap = [];
        $regionId = 0;
        $regionSizes = [];

        // 4-connected flood fill
        $directions = [[1, 0], [-1, 0], [0, 1], [0, -1]];

        foreach ($cells as $idx => $height) {
            if (isset($regionMap[$idx])) {
                continue;
            }

            // Start new region
            $regionId++;
            $size = 0;
            $stack = [$idx];

            while (count($stack) > 0) {
                $current = array_pop($stack);
                if (isset($regionMap[$current])) {
                    continue;
                }

                $regionMap[$current] = $regionId;
                $size++;

                $cx = $current % $width;
                $cz = (int) (($current - $cx) / $width);
                $currentHeight = $cells[$current];

                foreach ($directions as [$dx, $dz]) {
                    $nx = $cx + $dx;
                    $nz = $cz + $dz;

                    if ($nx < 0 || $nx >= $width || $nz < 0 || $nz >= $depth) {
                        continue;
                    }

                    $nIdx = $nz * $width + $nx;
                    if (!isset($cells[$nIdx]) || isset($regionMap[$nIdx])) {
                        continue;
                    }

                    // Connected if height difference is climbable
                    if (abs($cells[$nIdx] - $currentHeight) <= $maxClimb) {
                        $stack[] = $nIdx;
                    }
                }
            }

            $regionSizes[$regionId] = $size;
        }

        // Remove small regions
        $minSize = $this->config->regionMinSize;
        foreach ($regionSizes as $rid => $size) {
            if ($size < $minSize) {
                foreach ($regionMap as $idx => $r) {
                    if ($r === $rid) {
                        unset($regionMap[$idx]);
                    }
                }
            }
        }

        return $regionMap;
    }

    /**
     * Convert regions to NavMesh polygons and build adjacency.
     *
     * Each region becomes a set of triangulated polygons (one triangle
     * per cell pair). Adjacent region polygons that share edges are linked.
     *
     * @param array<int, int> $regionMap
     * @param array{width: int, depth: int, cells: array<int, float>} $heightfield
     * @param array{minX: float, minZ: float, maxX: float, maxZ: float, minY: float, maxY: float} $bounds
     */
    private function buildNavMesh(array $regionMap, array $heightfield, array $bounds): NavMesh
    {
        $width = $heightfield['width'];
        $cells = $heightfield['cells'];
        $cs = $this->config->cellSize;
        $minX = $bounds['minX'];
        $minZ = $bounds['minZ'];

        // Group cells by region
        /** @var array<int, int[]> regionId -> cell indices */
        $regionCells = [];
        foreach ($regionMap as $idx => $rid) {
            $regionCells[$rid][] = $idx;
        }

        $polygons = [];
        $polyId = 0;

        // For each region, triangulate the cells.
        // For every cell, try to form quads with right+below+diagonal neighbors.
        // Partial coverage: emit individual triangles where possible.
        foreach ($regionCells as $rid => $cellIndices) {
            /** @var array<int, true> Cells already covered by a quad to avoid duplicates */
            $coveredQuads = [];

            // First pass: emit quads (2 triangles) where all 4 cells exist
            foreach ($cellIndices as $idx) {
                $gx = $idx % $width;
                $gz = (int) (($idx - $gx) / $width);
                $h = $cells[$idx];

                $rightIdx = $idx + 1;
                $belowIdx = $idx + $width;
                $diagIdx = $idx + $width + 1;

                $hasRight = ($gx + 1 < $width) && isset($regionMap[$rightIdx]) && $regionMap[$rightIdx] === $rid;
                $hasBelow = isset($regionMap[$belowIdx]) && $regionMap[$belowIdx] === $rid;
                $hasDiag = ($gx + 1 < $width) && isset($regionMap[$diagIdx]) && $regionMap[$diagIdx] === $rid;

                if ($hasRight && $hasBelow && $hasDiag) {
                    $v00 = new Vec3($minX + $gx * $cs, $h, $minZ + $gz * $cs);
                    $v10 = new Vec3($minX + ($gx + 1) * $cs, $cells[$rightIdx], $minZ + $gz * $cs);
                    $v01 = new Vec3($minX + $gx * $cs, $cells[$belowIdx], $minZ + ($gz + 1) * $cs);
                    $v11 = new Vec3($minX + ($gx + 1) * $cs, $cells[$diagIdx], $minZ + ($gz + 1) * $cs);

                    $polygons[] = new NavMeshPolygon($polyId++, [$v00, $v10, $v11]);
                    $polygons[] = new NavMeshPolygon($polyId++, [$v00, $v11, $v01]);
                    $coveredQuads[$idx] = true;
                }
            }

            // Second pass: emit single triangles for uncovered cells with at least one neighbor
            foreach ($cellIndices as $idx) {
                if (isset($coveredQuads[$idx])) {
                    continue;
                }

                $gx = $idx % $width;
                $gz = (int) (($idx - $gx) / $width);
                $h = $cells[$idx];

                $rightIdx = $idx + 1;
                $belowIdx = $idx + $width;

                $hasRight = ($gx + 1 < $width) && isset($regionMap[$rightIdx]) && $regionMap[$rightIdx] === $rid;
                $hasBelow = isset($regionMap[$belowIdx]) && $regionMap[$belowIdx] === $rid;

                if ($hasRight && $hasBelow) {
                    $v00 = new Vec3($minX + $gx * $cs, $h, $minZ + $gz * $cs);
                    $v10 = new Vec3($minX + ($gx + 1) * $cs, $cells[$rightIdx], $minZ + $gz * $cs);
                    $v01 = new Vec3($minX + $gx * $cs, $cells[$belowIdx], $minZ + ($gz + 1) * $cs);
                    $polygons[] = new NavMeshPolygon($polyId++, [$v00, $v10, $v01]);
                } elseif ($hasRight) {
                    $diagIdx = $idx + $width + 1;
                    $hasDiag = ($gx + 1 < $width) && isset($regionMap[$diagIdx]) && $regionMap[$diagIdx] === $rid;
                    if ($hasDiag) {
                        $v00 = new Vec3($minX + $gx * $cs, $h, $minZ + $gz * $cs);
                        $v10 = new Vec3($minX + ($gx + 1) * $cs, $cells[$rightIdx], $minZ + $gz * $cs);
                        $v11 = new Vec3($minX + ($gx + 1) * $cs, $cells[$diagIdx], $minZ + ($gz + 1) * $cs);
                        $polygons[] = new NavMeshPolygon($polyId++, [$v00, $v10, $v11]);
                    }
                } elseif ($hasBelow) {
                    $diagIdx = $idx + $width + 1;
                    $hasDiag = ($gx + 1 < $width) && isset($regionMap[$diagIdx]) && $regionMap[$diagIdx] === $rid;
                    if ($hasDiag) {
                        $v00 = new Vec3($minX + $gx * $cs, $h, $minZ + $gz * $cs);
                        $v01 = new Vec3($minX + $gx * $cs, $cells[$belowIdx], $minZ + ($gz + 1) * $cs);
                        $v11 = new Vec3($minX + ($gx + 1) * $cs, $cells[$diagIdx], $minZ + ($gz + 1) * $cs);
                        $polygons[] = new NavMeshPolygon($polyId++, [$v00, $v11, $v01]);
                    }
                }
            }
        }

        if (count($polygons) === 0) {
            return new NavMesh();
        }

        // Build NavMesh with auto-detected adjacency
        return NavMesh::buildFromPolygons($polygons, $this->config->cellSize * 8.0);
    }

    private function triMinX(Triangle $tri): float
    {
        return min($tri->v0->x, $tri->v1->x, $tri->v2->x);
    }

    private function triMaxX(Triangle $tri): float
    {
        return max($tri->v0->x, $tri->v1->x, $tri->v2->x);
    }

    private function triMinZ(Triangle $tri): float
    {
        return min($tri->v0->z, $tri->v1->z, $tri->v2->z);
    }

    private function triMaxZ(Triangle $tri): float
    {
        return max($tri->v0->z, $tri->v1->z, $tri->v2->z);
    }
}
