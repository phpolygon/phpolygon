<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * High-level query interface for a NavMesh.
 *
 * Combines spatial lookup with pathfinding. The pathfinder is
 * injected so it can be swapped (A*, jump-point, hierarchical).
 */
class NavMeshQuery
{
    public function __construct(
        private readonly NavMesh $navMesh,
        private readonly PathfinderInterface $pathfinder,
    ) {}

    /**
     * Find the shortest path between two world positions.
     */
    public function findPath(Vec3 $start, Vec3 $end): ?NavMeshPath
    {
        $startPoly = $this->navMesh->findNearestPolygon($start);
        $endPoly = $this->navMesh->findNearestPolygon($end);

        if ($startPoly === null || $endPoly === null) {
            return null;
        }

        return $this->pathfinder->findPath($this->navMesh, $startPoly->id, $endPoly->id, $start, $end);
    }

    /**
     * Raycast along the NavMesh surface (XZ plane).
     *
     * Returns the hit position and the polygon ID where the ray exits
     * the NavMesh, or null if the entire ray stays on the mesh.
     *
     * @return array{position: Vec3, polygonId: int, t: float}|null
     */
    public function raycast(Vec3 $origin, Vec3 $direction, float $maxDistance): ?array
    {
        $dirNorm = $direction->normalize();
        $step = 0.5; // step size along ray
        $t = 0.0;
        $prevPoly = $this->navMesh->findPolygonAt($origin);

        while ($t < $maxDistance) {
            $t += $step;
            $pos = $origin->add($dirNorm->mul($t));
            $poly = $this->navMesh->findPolygonAt($pos);

            if ($poly === null) {
                // Ray left the NavMesh
                return [
                    'position' => $pos,
                    'polygonId' => $prevPoly !== null ? $prevPoly->id : -1,
                    't' => $t,
                ];
            }

            $prevPoly = $poly;
        }

        return null;
    }

    /**
     * Find all polygons in a radius around a position.
     *
     * @return NavMeshPolygon[]
     */
    public function findPolygonsInRadius(Vec3 $center, float $radius): array
    {
        return $this->navMesh->findPolygonsInRadius($center, $radius);
    }

    /**
     * Check if a position is on the NavMesh.
     */
    public function isOnNavMesh(Vec3 $position): bool
    {
        return $this->navMesh->findPolygonAt($position) !== null;
    }

    /**
     * Get the closest point on the NavMesh to a position.
     */
    public function closestPoint(Vec3 $position): ?Vec3
    {
        return $this->navMesh->projectPoint($position);
    }

    public function getNavMesh(): NavMesh
    {
        return $this->navMesh;
    }
}
