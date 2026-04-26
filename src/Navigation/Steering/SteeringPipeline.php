<?php

declare(strict_types=1);

namespace PHPolygon\Navigation\Steering;

use PHPolygon\Math\Vec3;

/**
 * Combines multiple steering behaviors with priority-based weighting.
 *
 * Higher-priority behaviors consume available steering budget first.
 * Lower-priority behaviors split whatever budget remains.
 */
class SteeringPipeline
{
    /** @var array{behavior: SteeringBehavior, weight: float, priority: int}[] */
    private array $behaviors = [];

    /**
     * Add a behavior with weight and priority (higher = evaluated first).
     */
    public function add(SteeringBehavior $behavior, float $weight = 1.0, int $priority = 0): self
    {
        $this->behaviors[] = [
            'behavior' => $behavior,
            'weight' => $weight,
            'priority' => $priority,
        ];

        // Sort by priority descending
        usort($this->behaviors, fn(array $a, array $b) => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * Calculate the combined steering force from all behaviors.
     *
     * @param array<string, mixed> $context Shared data for all behaviors.
     */
    public function calculate(Vec3 $position, Vec3 $velocity, float $maxSpeed, array $context): Vec3
    {
        $totalForce = Vec3::zero();
        $remainingBudget = $maxSpeed;

        foreach ($this->behaviors as $entry) {
            if ($remainingBudget <= 0.0) {
                break;
            }

            $force = $entry['behavior']->calculate($position, $velocity, $maxSpeed, $context);
            $weighted = $force->mul($entry['weight']);
            $magnitude = $weighted->length();

            if ($magnitude > $remainingBudget) {
                // Clamp to remaining budget
                $weighted = $weighted->normalize()->mul($remainingBudget);
                $magnitude = $remainingBudget;
            }

            $totalForce = $totalForce->add($weighted);
            $remainingBudget -= $magnitude;
        }

        return $totalForce;
    }

    /**
     * Remove all behaviors.
     */
    public function clear(): void
    {
        $this->behaviors = [];
    }

    // --- Built-in behavior factories ---

    /**
     * Seek: steer toward a target at max speed.
     */
    public static function seek(Vec3 $target): SteeringBehavior
    {
        return new class($target) implements SteeringBehavior {
            public function __construct(private readonly Vec3 $target) {}

            public function calculate(Vec3 $position, Vec3 $velocity, float $maxSpeed, array $context): Vec3
            {
                $desired = $this->target->sub($position)->normalize()->mul($maxSpeed);
                return $desired->sub($velocity);
            }
        };
    }

    /**
     * Flee: steer away from a target at max speed.
     */
    public static function flee(Vec3 $target): SteeringBehavior
    {
        return new class($target) implements SteeringBehavior {
            public function __construct(private readonly Vec3 $target) {}

            public function calculate(Vec3 $position, Vec3 $velocity, float $maxSpeed, array $context): Vec3
            {
                $desired = $position->sub($this->target)->normalize()->mul($maxSpeed);
                return $desired->sub($velocity);
            }
        };
    }

    /**
     * Arrive: seek with deceleration near the target.
     */
    public static function arrive(Vec3 $target, float $slowingRadius = 3.0): SteeringBehavior
    {
        return new class($target, $slowingRadius) implements SteeringBehavior {
            public function __construct(
                private readonly Vec3 $target,
                private readonly float $slowingRadius,
            ) {}

            public function calculate(Vec3 $position, Vec3 $velocity, float $maxSpeed, array $context): Vec3
            {
                $toTarget = $this->target->sub($position);
                $dist = $toTarget->length();

                if ($dist < 1e-6) {
                    return $velocity->mul(-1.0);
                }

                $speed = $dist < $this->slowingRadius
                    ? $maxSpeed * ($dist / $this->slowingRadius)
                    : $maxSpeed;

                $desired = $toTarget->normalize()->mul($speed);
                return $desired->sub($velocity);
            }
        };
    }

    /**
     * Separation: steer away from nearby agents.
     *
     * Context key 'neighbors' must contain Vec3[] of nearby agent positions.
     */
    public static function separation(float $desiredDistance = 2.0): SteeringBehavior
    {
        return new class($desiredDistance) implements SteeringBehavior {
            public function __construct(private readonly float $desiredDistance) {}

            public function calculate(Vec3 $position, Vec3 $velocity, float $maxSpeed, array $context): Vec3
            {
                /** @var Vec3[] $neighbors */
                $neighbors = $context['neighbors'] ?? [];
                if (count($neighbors) === 0) {
                    return Vec3::zero();
                }

                $force = Vec3::zero();
                $desiredSq = $this->desiredDistance * $this->desiredDistance;

                foreach ($neighbors as $neighborPos) {
                    $away = $position->sub($neighborPos);
                    $distSq = $away->lengthSquared();

                    if ($distSq < 1e-6 || $distSq > $desiredSq) {
                        continue;
                    }

                    // Inverse-distance weighting
                    $force = $force->add($away->normalize()->mul(1.0 - sqrt($distSq) / $this->desiredDistance));
                }

                return $force->mul($maxSpeed);
            }
        };
    }

    /**
     * PathFollow: steer along a path defined by waypoints.
     *
     * Context key 'pathTarget' must contain a Vec3 (current point on path).
     */
    public static function pathFollow(): SteeringBehavior
    {
        return new class implements SteeringBehavior {
            public function calculate(Vec3 $position, Vec3 $velocity, float $maxSpeed, array $context): Vec3
            {
                /** @var Vec3|null $target */
                $target = $context['pathTarget'] ?? null;
                if ($target === null) {
                    return Vec3::zero();
                }

                $desired = $target->sub($position)->normalize()->mul($maxSpeed);
                return $desired->sub($velocity);
            }
        };
    }
}
