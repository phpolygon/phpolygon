<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * Contract for NavMesh pathfinding algorithms.
 *
 * Implementations receive start/end polygon IDs (already resolved
 * from world positions) and return a smoothed path.
 */
interface PathfinderInterface
{
    public function findPath(
        NavMesh $navMesh,
        int $startPolygonId,
        int $endPolygonId,
        Vec3 $startPos,
        Vec3 $endPos,
    ): ?NavMeshPath;
}
