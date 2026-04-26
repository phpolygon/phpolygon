<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\Steering\LocalAvoidance;

class LocalAvoidanceTest extends TestCase
{
    public function testNoNeighborsReturnsDesiredVelocity(): void
    {
        $avoidance = new LocalAvoidance();
        $desired = new Vec3(5, 0, 0);
        $result = $avoidance->computeAvoidance(
            new Vec3(0, 0, 0),
            $desired,
            0.5,
            [],
        );

        $this->assertTrue($result->equals($desired));
    }

    public function testAvoidanceAdjustsVelocityForOncomingAgent(): void
    {
        $avoidance = new LocalAvoidance();

        $result = $avoidance->computeAvoidance(
            new Vec3(0, 0, 0),
            new Vec3(5, 0, 0),    // Moving right
            0.5,
            [
                [
                    'position' => new Vec3(5, 0, 0),
                    'velocity' => new Vec3(-5, 0, 0), // Moving left (head-on)
                    'radius' => 0.5,
                ],
            ],
        );

        // Should deviate from pure X direction
        $this->assertGreaterThan(0.01, abs($result->z));
    }

    public function testAvoidanceIgnoresDivergingAgents(): void
    {
        $avoidance = new LocalAvoidance();

        $desired = new Vec3(5, 0, 0);
        $result = $avoidance->computeAvoidance(
            new Vec3(0, 0, 0),
            $desired,
            0.5,
            [
                [
                    'position' => new Vec3(-5, 0, 0),   // Behind us
                    'velocity' => new Vec3(-5, 0, 0),   // Moving further away
                    'radius' => 0.5,
                ],
            ],
        );

        // Should not deviate much
        $this->assertEqualsWithDelta($desired->x, $result->x, 0.5);
    }

    public function testOverlappingAgentsPushApart(): void
    {
        $avoidance = new LocalAvoidance();

        $result = $avoidance->computeAvoidance(
            new Vec3(0, 0, 0),
            new Vec3(1, 0, 0),
            1.0,
            [
                [
                    'position' => new Vec3(0.5, 0, 0), // Overlapping
                    'velocity' => Vec3::zero(),
                    'radius' => 1.0,
                ],
            ],
        );

        // Should have some push-out force
        $this->assertGreaterThan(0.0, $result->length());
    }

    public function testResultClampedToDesiredSpeed(): void
    {
        $avoidance = new LocalAvoidance();
        $desired = new Vec3(3, 0, 0);

        $result = $avoidance->computeAvoidance(
            new Vec3(0, 0, 0),
            $desired,
            0.5,
            [
                [
                    'position' => new Vec3(3, 0, 0),
                    'velocity' => new Vec3(-3, 0, 0),
                    'radius' => 0.5,
                ],
            ],
        );

        // Result speed should not exceed desired speed
        $this->assertLessThanOrEqual($desired->length() + 0.01, $result->length());
    }
}
