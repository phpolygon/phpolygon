<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\NavMeshAgent;
use PHPolygon\Component\NavMeshObstacle;
use PHPolygon\Component\NavMeshSurface;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
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
 * - Advances agents along their paths (steering), NavMesh paths and authored ones
 *   ({@see NavMeshAgent::followPath()}, which also move without any NavMesh)
 * - Stands agents out at wait points, turns them (faceMovement, face target on arrival)
 *   and moves them through a CharacterController3D when asked to
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

        foreach ($world->query(NavMeshAgent::class, Transform3D::class) as $entity) {
            $agent = $entity->get(NavMeshAgent::class);
            $transform = $entity->get(Transform3D::class);

            if ($agent->isStopped) {
                continue;
            }

            // Without a NavMesh only authored paths (followPath) move; a pending
            // destination waits until there is one to search.
            if ($query === null && !$agent->hasPath) {
                continue;
            }

            // Compute path if pending
            if ($query !== null && $agent->isPathPending && $agent->destination !== null) {
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
                $controller = $agent->useCharacterController ? $entity->tryGet(CharacterController3D::class) : null;
                $this->advanceAgent($agent, $transform, $dt, $controller instanceof CharacterController3D);
            }
        }
    }


    /**
     * Move an agent along its current path toward the next waypoint: stands out its
     * wait points, slows down toward the next stop (a wait point or the end), turns
     * toward its travel direction with faceMovement and toward its face target on
     * arrival. $horizontalOnly = moved through a CharacterController3D: only the
     * horizontal step is written, the physics settles collisions and height.
     */
    private function advanceAgent(NavMeshAgent $agent, Transform3D $transform, float $dt, bool $horizontalOnly = false): void
    {
        $path = $agent->currentPath;
        if ($path === null) {
            return;
        }

        if ($agent->waitTimer > 0.0) {
            $agent->waitTimer = max(0.0, $agent->waitTimer - $dt);
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
            if ($this->beginWait($agent, $agent->currentWaypointIndex)) {
                return;
            }
            $agent->currentWaypointIndex++;
        }

        $target = $waypoints[$agent->currentWaypointIndex];
        $toTarget = $target->sub($pos);
        if ($horizontalOnly) {
            $toTarget = new Vec3($toTarget->x, 0.0, $toTarget->z);
        }
        $distToTarget = sqrt($toTarget->x * $toTarget->x + $toTarget->z * $toTarget->z);

        // Check if we've reached the final waypoint
        if ($agent->currentWaypointIndex === $waypointCount - 1 && $distToTarget <= $agent->stoppingDistance) {
            $agent->desiredVelocity = Vec3::zero();
            $agent->currentVelocity = Vec3::zero();
            $this->arrive($agent, $transform, $dt);
            return;
        }

        // Compute desired velocity toward target
        $direction = $toTarget->normalize();
        $desiredSpeed = $agent->speed;

        // Slow down when approaching the next stop (arrive behavior): the final
        // destination, or a wait point still to be served.
        $stop = $waypointCount - 1;
        foreach ($agent->waits as $index => $seconds) {
            if ($seconds > 0.0 && $index >= $agent->currentWaypointIndex && $index > $agent->waitedIndex && $index < $stop) {
                $stop = $index;
            }
        }
        // Only "closer than one second of walking?" matters, so the sum stops there:
        // a long path costs no more per tick than a short one.
        $remaining = $distToTarget;
        for ($i = $agent->currentWaypointIndex; $i < $stop && $remaining < $agent->speed; $i++) {
            $remaining += sqrt($waypoints[$i]->distanceSquaredTo($waypoints[$i + 1]));
        }
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
        $transform->position = $horizontalOnly
            ? new Vec3($pos->x + $movement->x, $pos->y, $pos->z + $movement->z)
            : $pos->add($movement);

        $v = $agent->currentVelocity;
        if ($agent->faceMovement && $v->x * $v->x + $v->z * $v->z > 1e-8) {
            self::turn($agent, $transform, atan2($v->x, $v->z), $dt);
        }
    }

    /** Start standing at waypoint $index when it is a wait point not served yet. */
    private function beginWait(NavMeshAgent $agent, int $index): bool
    {
        $seconds = $agent->waits[$index] ?? 0.0;
        if ($seconds <= 0.0 || $index <= $agent->waitedIndex) {
            return false;
        }
        $agent->waitedIndex = $index;
        $agent->waitTimer = $seconds;
        $agent->desiredVelocity = Vec3::zero();
        $agent->currentVelocity = Vec3::zero();
        return true;
    }

    /** At the destination: turn toward the face target, then count as arrived. */
    private function arrive(NavMeshAgent $agent, Transform3D $transform, float $dt): void
    {
        if ($agent->arrived) {
            return;
        }
        $face = $agent->faceTarget;
        if ($face === null) {
            $agent->arrived = true;
            return;
        }
        $dx = $face->x - $transform->position->x;
        $dz = $face->z - $transform->position->z;
        $agent->arrived = $dx * $dx + $dz * $dz < 1e-8 || self::turn($agent, $transform, atan2($dx, $dz), $dt);
    }

    /**
     * Turn the entity's yaw toward $targetYaw (travel direction, radians) by at most
     * angularSpeed degrees per second. Returns whether it got there.
     */
    private static function turn(NavMeshAgent $agent, Transform3D $transform, float $targetYaw, float $dt): bool
    {
        if ($agent->yaw === null) {
            $forward = $transform->rotation->rotateVec3(new Vec3(0.0, 0.0, 1.0));
            $agent->yaw = atan2($forward->x, $forward->z) - $agent->yawOffset;
        }
        $diff = fmod($targetYaw - $agent->yaw + M_PI, 2.0 * M_PI);
        $diff = ($diff < 0.0 ? $diff + 2.0 * M_PI : $diff) - M_PI;
        $maxStep = $agent->angularSpeed > 0.0 ? deg2rad($agent->angularSpeed) * $dt : INF;
        $reached = abs($diff) <= $maxStep;
        $yaw = $reached ? $targetYaw : $agent->yaw + ($diff > 0.0 ? $maxStep : -$maxStep);
        $agent->yaw = fmod($yaw, 2.0 * M_PI);
        $transform->rotation = Quaternion::fromEuler(0.0, $agent->yaw + $agent->yawOffset, 0.0);
        return $reached;
    }
}