<?php

declare(strict_types=1);

namespace PHPolygon\Navigation\Steering;

use PHPolygon\Math\Vec3;

/**
 * Contract for individual steering behaviors.
 *
 * Each behavior computes a steering force (Vec3) that the
 * SteeringPipeline combines with other behaviors.
 */
interface SteeringBehavior
{
    /**
     * Calculate the steering force for this behavior.
     *
     * @param Vec3 $position Current agent position.
     * @param Vec3 $velocity Current agent velocity.
     * @param float $maxSpeed Maximum movement speed.
     * @param array<string, mixed> $context Shared context (nearby agents, targets, etc.)
     * @return Vec3 The steering force vector.
     */
    public function calculate(Vec3 $position, Vec3 $velocity, float $maxSpeed, array $context): Vec3;
}
