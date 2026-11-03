<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\NavMeshPath;

/**
 * Agent that navigates along a NavMesh.
 *
 * Stores the agent's navigation parameters and runtime state.
 * The NavigationSystem reads this data and computes movement
 * via pathfinding and steering behaviors.
 *
 * Besides a destination searched on the NavMesh ({@see setDestination()}) an agent
 * can walk an authored waypoint list ({@see followPath()}) with wait points - that
 * needs no NavMesh. Arrival ({@see hasArrived()}) includes turning toward an optional
 * face target. Turning while walking ({@see $faceMovement}) and moving through a
 * CharacterController3D ({@see $useCharacterController}) are opt-in; with both off
 * the agent moves exactly as it did before they existed.
 */
#[Serializable]
#[Category('Navigation')]
class NavMeshAgent extends AbstractComponent
{
    #[Property]
    public float $speed;

    #[Property]
    public float $angularSpeed;

    #[Property]
    public float $acceleration;

    #[Property]
    public float $stoppingDistance;

    #[Property]
    public float $avoidanceRadius;

    #[Property]
    public int $avoidancePriority;

    /**
     * Turn the entity toward its travel direction (yaw only, the figure stays upright),
     * at most {@see $angularSpeed} degrees per second (<= 0: at once). Off by default:
     * agents used to be moved without being turned.
     */
    #[Property]
    public bool $faceMovement = false;

    /**
     * Yaw added to the travel direction, radians. 0 = the model looks along its local +Z;
     * π for a model that looks along -Z.
     */
    #[Property]
    public float $yawOffset = 0.0;

    /**
     * Move through the entity's {@see CharacterController3D}: only the horizontal step is
     * written and the physics resolves collisions, steps and height. Without a controller
     * on the entity (or with this off) the position is written directly, height included.
     */
    #[Property]
    public bool $useCharacterController = false;

    // --- Runtime state (not serialized) ---

    #[Hidden]
    public ?NavMeshPath $currentPath = null;

    #[Hidden]
    public int $currentWaypointIndex = 0;

    #[Hidden]
    public ?Vec3 $destination = null;

    #[Hidden]
    public Vec3 $desiredVelocity;

    #[Hidden]
    public Vec3 $currentVelocity;

    #[Hidden]
    public bool $hasPath = false;

    #[Hidden]
    public bool $isPathPending = false;

    #[Hidden]
    public bool $isStopped = false;

    /** Point to turn toward once the destination is reached; null = keep the last facing. */
    #[Hidden]
    public ?Vec3 $faceTarget = null;

    /** @var array<int, float> waypoint index => seconds to stand there (followPath) */
    #[Hidden]
    public array $waits = [];

    /** Seconds left at the current wait point. */
    #[Hidden]
    public float $waitTimer = 0.0;

    /** Highest waypoint index whose wait was served. */
    #[Hidden]
    public int $waitedIndex = -1;

    /** Destination reached and, with a face target, turned toward it. */
    #[Hidden]
    public bool $arrived = false;

    /** Current yaw the agent wrote (radians, before $yawOffset); null until it first turns. */
    #[Hidden]
    public ?float $yaw = null;

    public function __construct(
        float $speed = 3.5,
        float $angularSpeed = 120.0,
        float $acceleration = 8.0,
        float $stoppingDistance = 0.5,
        float $avoidanceRadius = 0.5,
        int $avoidancePriority = 50,
    ) {
        $this->speed = $speed;
        $this->angularSpeed = $angularSpeed;
        $this->acceleration = $acceleration;
        $this->stoppingDistance = $stoppingDistance;
        $this->avoidanceRadius = $avoidanceRadius;
        $this->avoidancePriority = $avoidancePriority;
        $this->desiredVelocity = Vec3::zero();
        $this->currentVelocity = Vec3::zero();
    }

    /**
     * Set a new destination. The NavigationSystem will compute the path.
     */
    public function setDestination(Vec3 $target, ?Vec3 $faceTarget = null): void
    {
        $this->destination = $target;
        $this->isPathPending = true;
        $this->isStopped = false;
        $this->faceTarget = $faceTarget;
        $this->waits = [];
        $this->waitTimer = 0.0;
        $this->waitedIndex = -1;
        $this->arrived = false;
    }

    /**
     * Walk an authored list of waypoints instead of a NavMesh path - for places without
     * a NavMesh (halls, scripted routes). Needs no NavMesh: the NavigationSystem moves the
     * agent along it either way.
     *
     * @param list<Vec3> $waypoints in order; the first may be where the agent stands
     * @param array<int, float> $waits waypoint index => seconds to stand there
     * @param Vec3|null $faceTarget point to turn toward on arrival
     */
    public function followPath(array $waypoints, array $waits = [], ?Vec3 $faceTarget = null): void
    {
        if ($waypoints === []) {
            $this->stop();
            return;
        }
        $this->currentPath = new NavMeshPath($waypoints);
        $this->currentWaypointIndex = 0;
        $this->destination = $waypoints[count($waypoints) - 1];
        $this->hasPath = true;
        $this->isPathPending = false;
        $this->isStopped = false;
        $this->faceTarget = $faceTarget;
        $this->waits = $waits;
        $this->waitTimer = 0.0;
        $this->waitedIndex = -1;
        $this->arrived = false;
    }

    /** Whether the agent stands at a wait point of its path. */
    public function isWaiting(): bool
    {
        return $this->waitTimer > 0.0;
    }

    /** Destination reached and turned toward the face target (when one was given). */
    public function hasArrived(): bool
    {
        return $this->arrived;
    }

    /**
     * Stop navigation and clear the current path.
     */
    public function stop(): void
    {
        $this->destination = null;
        $this->currentPath = null;
        $this->currentWaypointIndex = 0;
        $this->hasPath = false;
        $this->isPathPending = false;
        $this->isStopped = true;
        $this->desiredVelocity = Vec3::zero();
        $this->waitTimer = 0.0;
        $this->arrived = false;
    }

    /**
     * Remaining distance along the current path.
     */
    public function remainingDistance(): float
    {
        if ($this->currentPath === null) {
            return 0.0;
        }

        return $this->currentPath->remainingDistance($this->currentWaypointIndex);
    }

    /**
     * Whether the agent has reached its destination.
     */
    public function hasReachedDestination(): bool
    {
        return $this->hasPath && $this->remainingDistance() <= $this->stoppingDistance;
    }
}
