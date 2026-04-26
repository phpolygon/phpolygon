<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

use PHPolygon\Math\Vec3;

/**
 * Result of a NavMesh pathfinding query.
 *
 * Contains the smoothed waypoint list (world positions) and
 * the polygon IDs the path traverses.
 */
readonly class NavMeshPath
{
    public float $totalLength;

    /**
     * @param Vec3[] $waypoints Smoothed world-space positions.
     * @param int[] $polygonIds NavMesh polygon IDs along the path.
     * @param float $totalCost A* cost (heuristic-weighted).
     * @param bool $isPartial True if the path could not fully reach the target.
     */
    public function __construct(
        public array $waypoints,
        public array $polygonIds = [],
        public float $totalCost = 0.0,
        public bool $isPartial = false,
    ) {
        $this->totalLength = self::computeLength($waypoints);
    }

    /**
     * Interpolate a position along the path at a given travel distance.
     */
    public function getPointAtDistance(float $distance): Vec3
    {
        if (count($this->waypoints) === 0) {
            return Vec3::zero();
        }

        if ($distance <= 0.0) {
            return $this->waypoints[0];
        }

        $traveled = 0.0;
        for ($i = 0, $n = count($this->waypoints) - 1; $i < $n; $i++) {
            $segLen = sqrt($this->waypoints[$i]->distanceSquaredTo($this->waypoints[$i + 1]));
            if ($segLen < 1e-10) {
                continue;
            }

            if ($traveled + $segLen >= $distance) {
                $t = ($distance - $traveled) / $segLen;
                return $this->waypoints[$i]->lerp($this->waypoints[$i + 1], $t);
            }

            $traveled += $segLen;
        }

        return $this->waypoints[count($this->waypoints) - 1];
    }

    /**
     * Remaining distance from a given waypoint index to the end.
     */
    public function remainingDistance(int $fromIndex): float
    {
        $dist = 0.0;
        for ($i = max(0, $fromIndex), $n = count($this->waypoints) - 1; $i < $n; $i++) {
            $dist += sqrt($this->waypoints[$i]->distanceSquaredTo($this->waypoints[$i + 1]));
        }
        return $dist;
    }

    public function waypointCount(): int
    {
        return count($this->waypoints);
    }

    /**
     * @return array{waypoints: list<array{x: float, y: float, z: float}>, polygonIds: int[], totalCost: float, isPartial: bool}
     */
    public function toArray(): array
    {
        return [
            'waypoints' => array_values(array_map(fn(Vec3 $v) => $v->toArray(), $this->waypoints)),
            'polygonIds' => $this->polygonIds,
            'totalCost' => $this->totalCost,
            'isPartial' => $this->isPartial,
        ];
    }

    /**
     * @param array{waypoints: list<array{x: float, y: float, z: float}>, polygonIds: int[], totalCost: float, isPartial: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            waypoints: array_map(fn(array $v) => Vec3::fromArray($v), $data['waypoints']),
            polygonIds: $data['polygonIds'],
            totalCost: $data['totalCost'],
            isPartial: $data['isPartial'],
        );
    }

    /**
     * @param Vec3[] $waypoints
     */
    private static function computeLength(array $waypoints): float
    {
        $length = 0.0;
        for ($i = 0, $n = count($waypoints) - 1; $i < $n; $i++) {
            $length += sqrt($waypoints[$i]->distanceSquaredTo($waypoints[$i + 1]));
        }
        return $length;
    }
}
