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
    public function setDestination(Vec3 $target): void
    {
        $this->destination = $target;
        $this->isPathPending = true;
        $this->isStopped = false;
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
