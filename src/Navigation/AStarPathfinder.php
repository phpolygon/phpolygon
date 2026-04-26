<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * A* pathfinding on a NavMesh polygon graph.
 *
 * Finds the lowest-cost polygon corridor, then applies the
 * funnel algorithm for a smooth world-space path.
 */
class AStarPathfinder implements PathfinderInterface
{
    private FunnelSmoother $smoother;

    public function __construct(?FunnelSmoother $smoother = null)
    {
        $this->smoother = $smoother ?? new FunnelSmoother();
    }

    public function findPath(
        NavMesh $navMesh,
        int $startPolygonId,
        int $endPolygonId,
        Vec3 $startPos,
        Vec3 $endPos,
    ): ?NavMeshPath {
        // Trivial case: start and end are in the same polygon
        if ($startPolygonId === $endPolygonId) {
            return new NavMeshPath(
                waypoints: [$startPos, $endPos],
                polygonIds: [$startPolygonId],
            );
        }

        $endPoly = $navMesh->getPolygon($endPolygonId);
        if ($endPoly === null) {
            return null;
        }

        // A* search
        $openSet = new BinaryHeap();
        /** @var array<int, float> g-cost: actual cost from start */
        $gCost = [];
        /** @var array<int, int> cameFrom: polygon ID -> predecessor polygon ID */
        $cameFrom = [];
        /** @var array<int, true> closedSet */
        $closedSet = [];

        $gCost[$startPolygonId] = 0.0;
        $h = sqrt($startPos->distanceSquaredTo($endPos));
        $openSet->insert($startPolygonId, $h);

        while (!$openSet->isEmpty()) {
            $entry = $openSet->extractMin();
            if ($entry === null) {
                break;
            }

            $currentId = $entry[0];

            if ($currentId === $endPolygonId) {
                return $this->reconstructPath($navMesh, $cameFrom, $startPolygonId, $endPolygonId, $startPos, $endPos);
            }

            $closedSet[$currentId] = true;

            $currentPoly = $navMesh->getPolygon($currentId);
            if ($currentPoly === null) {
                continue;
            }

            $neighborCount = count($currentPoly->neighborIds);
            for ($i = 0; $i < $neighborCount; $i++) {
                $neighborId = $currentPoly->neighborIds[$i];

                if (isset($closedSet[$neighborId])) {
                    continue;
                }

                $edgeCost = $currentPoly->edgeCosts[$i];
                $tentativeG = $gCost[$currentId] + $edgeCost;

                if (!isset($gCost[$neighborId]) || $tentativeG < $gCost[$neighborId]) {
                    $gCost[$neighborId] = $tentativeG;
                    $cameFrom[$neighborId] = $currentId;

                    $neighborPoly = $navMesh->getPolygon($neighborId);
                    $heuristic = $neighborPoly !== null
                        ? sqrt($neighborPoly->centroid->distanceSquaredTo($endPoly->centroid))
                        : 0.0;

                    $fCost = $tentativeG + $heuristic;

                    if ($openSet->contains($neighborId)) {
                        $openSet->decreaseKey($neighborId, $fCost);
                    } else {
                        $openSet->insert($neighborId, $fCost);
                    }
                }
            }
        }

        // No path found
        return null;
    }

    /**
     * Reconstruct the polygon corridor and smooth it via funnel algorithm.
     *
     * @param array<int, int> $cameFrom
     */
    private function reconstructPath(
        NavMesh $navMesh,
        array $cameFrom,
        int $startId,
        int $endId,
        Vec3 $startPos,
        Vec3 $endPos,
    ): NavMeshPath {
        // Build corridor (reverse order)
        $corridor = [$endId];
        $current = $endId;

        while ($current !== $startId) {
            $current = $cameFrom[$current];
            $corridor[] = $current;
        }

        $corridor = array_reverse($corridor);

        // Collect portal edges between consecutive corridor polygons
        $portals = [];
        for ($i = 0, $n = count($corridor) - 1; $i < $n; $i++) {
            $edge = $navMesh->getEdgeBetween($corridor[$i], $corridor[$i + 1]);
            if ($edge !== null) {
                $portals[] = $edge;
            }
        }

        // Smooth with funnel algorithm
        $smoothed = $this->smoother->smooth($startPos, $endPos, $portals);

        return new NavMeshPath(
            waypoints: $smoothed->waypoints,
            polygonIds: $corridor,
            totalCost: $smoothed->totalLength,
        );
    }
}
