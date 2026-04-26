<?php

declare(strict_types=1);

namespace PHPolygon\Navigation\Steering;

use PHPolygon\Math\Vec3;

/**
 * Velocity-obstacle based local collision avoidance.
 *
 * Simplified RVO (Reciprocal Velocity Obstacles): given the agent's
 * desired velocity and nearby agent positions/velocities, computes
 * a corrected velocity that avoids imminent collisions.
 */
class LocalAvoidance
{
    /**
     * Compute an adjusted velocity that avoids collisions.
     *
     * @param Vec3 $position This agent's position.
     * @param Vec3 $desiredVelocity This agent's desired velocity.
     * @param float $radius This agent's avoidance radius.
     * @param array{position: Vec3, velocity: Vec3, radius: float}[] $neighbors Nearby agents.
     * @param float $timeHorizon How far ahead to predict (seconds).
     */
    public function computeAvoidance(
        Vec3 $position,
        Vec3 $desiredVelocity,
        float $radius,
        array $neighbors,
        float $timeHorizon = 1.5,
    ): Vec3 {
        if (count($neighbors) === 0) {
            return $desiredVelocity;
        }

        $adjustedVelocity = $desiredVelocity;

        foreach ($neighbors as $neighbor) {
            $relPos = $neighbor['position']->sub($position);
            $relVel = $desiredVelocity->sub($neighbor['velocity']);
            $combinedRadius = $radius + $neighbor['radius'];

            $distSq = $relPos->lengthSquared();
            $combinedRadiusSq = $combinedRadius * $combinedRadius;

            // Already overlapping - push directly away
            if ($distSq < $combinedRadiusSq) {
                $dist = sqrt($distSq);
                if ($dist > 1e-6) {
                    $pushDir = $relPos->mul(-1.0)->normalize();
                    $adjustedVelocity = $adjustedVelocity->add($pushDir->mul($combinedRadius - $dist));
                }
                continue;
            }

            // Project relative velocity onto the relative position
            // to check if a collision is predicted within timeHorizon
            $dot = $relVel->dot($relPos);
            if ($dot <= 0.0) {
                // Moving apart
                continue;
            }

            // Time to closest approach
            $relSpeed = $relVel->lengthSquared();
            if ($relSpeed < 1e-10) {
                continue;
            }

            $tca = $dot / $relSpeed;
            if ($tca > $timeHorizon) {
                continue;
            }

            // Closest approach distance squared
            $closestApproach = $relPos->sub($relVel->mul($tca));
            $closestDistSq = $closestApproach->lengthSquared();

            if ($closestDistSq >= $combinedRadiusSq) {
                // No collision predicted
                continue;
            }

            // Compute avoidance: steer perpendicular to the relative position
            $perpendicular = new Vec3(-$relPos->z, 0.0, $relPos->x);
            $perpLen = $perpendicular->length();
            if ($perpLen < 1e-6) {
                continue;
            }

            $perpendicular = $perpendicular->normalize();

            // Strength: inversely proportional to time-to-collision
            $urgency = 1.0 - ($tca / $timeHorizon);
            $avoidanceForce = $perpendicular->mul($urgency * $desiredVelocity->length());

            $adjustedVelocity = $adjustedVelocity->add($avoidanceForce);
        }

        // Clamp to desired speed
        $desiredSpeed = $desiredVelocity->length();
        $adjustedSpeed = $adjustedVelocity->length();

        if ($adjustedSpeed > $desiredSpeed && $adjustedSpeed > 1e-6) {
            $adjustedVelocity = $adjustedVelocity->normalize()->mul($desiredSpeed);
        }

        return $adjustedVelocity;
    }
}
