<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\NavMeshAgent;
use PHPolygon\Component\NavMeshObstacle;
use PHPolygon\Component\NavMeshSurface;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\AStarPathfinder;
use PHPolygon\Navigation\NavMesh;
use PHPolygon\Navigation\NavMeshBuilder;
use PHPolygon\Navigation\NavMeshQuery;
use PHPolygon\Navigation\PathfinderInterface;

/**
 * Cross-entity system for NavMesh-based AI navigation.
 *
 * Responsibilities:
 * - Rebuilds NavMesh when surfaces are invalidated
 * - Computes paths for agents with pending destinations
 * - Advances agents along their paths (steering)
 * - Handles dynamic obstacle carving
 */
class NavigationSystem extends AbstractSystem
{
    private PathfinderInterface $pathfinder;
    private ?NavMeshQuery $query = null;
    private ?NavMesh $activeNavMesh = null;

    public function __construct(?PathfinderInterface $pathfinder = null)
    {
        $this->pathfinder = $pathfinder ?? new AStarPathfinder();
    }

    public function getNavMesh(): ?NavMesh
    {
        return $this->activeNavMesh;
    }

    public function getQuery(): ?NavMeshQuery
    {
        return $this->query;
    }

    /**
     * Inject a pre-built NavMesh (useful for tests and manual setup).
     */
    public function setNavMesh(NavMesh $navMesh): void
    {
        $this->activeNavMesh = $navMesh;
        $this->query = new NavMeshQuery($navMesh, $this->pathfinder);
    }

    public function update(World $world, float $dt): void
    {
        $this->rebuildIfNeeded($world);
        $this->processObstacles($world);
        $this->processAgents($world, $dt);
    }

    /**
     * Check NavMeshSurface components and rebuild if flagged.
     */
    private function rebuildIfNeeded(World $world): void
    {
        foreach ($world->query(NavMeshSurface::class) as $entity) {
            $surface = $entity->get(NavMeshSurface::class);

            if (!$surface->needsRebuild) {
                continue;
            }

            $builder = new NavMeshBuilder($surface->getConfig());
            $surface->navMesh = $builder->build($world);
            $surface->needsRebuild = false;

            // Use the first surface's NavMesh as active
            $this->activeNavMesh = $surface->navMesh;
            $this->query = new NavMeshQuery($this->activeNavMesh, $this->pathfinder);
        }
    }

    /**
     * Process dynamic obstacles - trigger NavMesh rebuild if moved.
     */
    private function processObstacles(World $world): void
    {
        if ($this->activeNavMesh === null) {
            return;
        }

        foreach ($world->query(NavMeshObstacle::class, Transform3D::class) as $entity) {
            $obstacle = $entity->get(NavMeshObstacle::class);
            $transform = $entity->get(Transform3D::class);

            if (!$obstacle->carve) {
                continue;
            }

            $pos = $transform->position;
            $threshold = $obstacle->carvingMoveThreshold;

            if ($obstacle->lastCarvedPosition === null
                || $pos->distanceSquaredTo($obstacle->lastCarvedPosition) > $threshold * $threshold
            ) {
                $obstacle->lastCarvedPosition = $pos;

                // Mark all surfaces for rebuild
                foreach ($world->query(NavMeshSurface::class) as $surfaceEntity) {
                    $surfaceEntity->get(NavMeshSurface::class)->invalidate();
                }
            }
        }
    }

    /**
     * Process all NavMeshAgents: compute paths and advance along them.
     */
    private function processAgents(World $world, float $dt): void
    {
        $query = $this->query;
        if ($query === null) {
            return;
        }

        foreach ($world->query(NavMeshAgent::class, Transform3D::class) as $entity) {
            $agent = $entity->get(NavMeshAgent::class);
            $transform = $entity->get(Transform3D::class);

            if ($agent->isStopped) {
                continue;
            }

            // Compute path if pending
            if ($agent->isPathPending && $agent->destination !== null) {
                $path = $query->findPath($transform->position, $agent->destination);
                if ($path !== null && $path->waypointCount() >= 2) {
                    $agent->currentPath = $path;
                    // Start at waypoint 1 (waypoint 0 is the start position)
                    $agent->currentWaypointIndex = 1;
                    $agent->hasPath = true;
                } else {
                    $agent->hasPath = false;
                }
                $agent->isPathPending = false;
            }

            // Advance along path
            if ($agent->hasPath && $agent->currentPath !== null) {
                $this->advanceAgent($agent, $transform, $dt);
            }
        }
    }

    /**
     * Move an agent along its current path toward the next waypoint.
     */
    private function advanceAgent(NavMeshAgent $agent, Transform3D $transform, float $dt): void
    {
        $path = $agent->currentPath;
        if ($path === null) {
            return;
        }

        $waypoints = $path->waypoints;
        $waypointCount = count($waypoints);

        if ($agent->currentWaypointIndex >= $waypointCount) {
            $agent->desiredVelocity = Vec3::zero();
            return;
        }

        $pos = $transform->position;

        // Advance past waypoints we're already close to (but not the last one)
        while ($agent->currentWaypointIndex < $waypointCount - 1) {
            $target = $waypoints[$agent->currentWaypointIndex];
            $toTarget = $target->sub($pos);
            $distXZ = sqrt($toTarget->x * $toTarget->x + $toTarget->z * $toTarget->z);
            if ($distXZ >= $agent->stoppingDistance) {
                break;
            }
            $agent->currentWaypointIndex++;
        }

        $target = $waypoints[$agent->currentWaypointIndex];
        $toTarget = $target->sub($pos);
        $distToTarget = sqrt($toTarget->x * $toTarget->x + $toTarget->z * $toTarget->z);

        // Check if we've reached the final waypoint
        if ($agent->currentWaypointIndex === $waypointCount - 1 && $distToTarget <= $agent->stoppingDistance) {
            $agent->desiredVelocity = Vec3::zero();
            $agent->currentVelocity = Vec3::zero();
            return;
        }

        // Compute desired velocity toward target
        $direction = $toTarget->normalize();
        $desiredSpeed = $agent->speed;

        // Slow down when approaching final destination (arrive behavior)
        $remaining = $distToTarget + $path->remainingDistance($agent->currentWaypointIndex);
        if ($remaining < $agent->speed * 1.0) {
            $desiredSpeed *= $remaining / ($agent->speed * 1.0);
        }

        $agent->desiredVelocity = $direction->mul($desiredSpeed);

        // Smooth acceleration
        $diff = $agent->desiredVelocity->sub($agent->currentVelocity);
        $maxAccel = $agent->acceleration * $dt;
        $diffLen = $diff->length();

        if ($diffLen > $maxAccel && $diffLen > 1e-6) {
            $agent->currentVelocity = $agent->currentVelocity->add($diff->normalize()->mul($maxAccel));
        } else {
            $agent->currentVelocity = $agent->desiredVelocity;
        }

        // Apply movement
        $movement = $agent->currentVelocity->mul($dt);
        $transform->position = $pos->add($movement);
    }
}
