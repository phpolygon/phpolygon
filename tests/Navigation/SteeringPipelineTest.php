<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\Steering\SteeringPipeline;

class SteeringPipelineTest extends TestCase
{
    public function testSeekSteersTowardTarget(): void
    {
        $behavior = SteeringPipeline::seek(new Vec3(10, 0, 0));
        $force = $behavior->calculate(
            new Vec3(0, 0, 0),  // position
            Vec3::zero(),        // velocity
            5.0,                 // maxSpeed
            [],
        );

        // Force should point in +X direction
        $this->assertGreaterThan(0.0, $force->x);
    }

    public function testFleeSteersAwayFromTarget(): void
    {
        $behavior = SteeringPipeline::flee(new Vec3(10, 0, 0));
        $force = $behavior->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            [],
        );

        // Force should point in -X direction
        $this->assertLessThan(0.0, $force->x);
    }

    public function testArriveSlowsDownNearTarget(): void
    {
        $behavior = SteeringPipeline::arrive(new Vec3(1, 0, 0), 5.0);

        // Close to target (within slowing radius)
        $force = $behavior->calculate(
            new Vec3(0.5, 0, 0),
            Vec3::zero(),
            10.0,
            [],
        );

        // Far from target
        $forceFar = $behavior->calculate(
            new Vec3(-10, 0, 0),
            Vec3::zero(),
            10.0,
            [],
        );

        // Close force magnitude should be less than far force magnitude
        $this->assertLessThan($forceFar->length(), $force->length());
    }

    public function testSeparationPushesAwayFromNeighbors(): void
    {
        $behavior = SteeringPipeline::separation(3.0);
        $force = $behavior->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            ['neighbors' => [new Vec3(1, 0, 0)]],
        );

        // Should push away from the neighbor (in -X direction)
        $this->assertLessThan(0.0, $force->x);
    }

    public function testSeparationReturnsZeroWithNoNeighbors(): void
    {
        $behavior = SteeringPipeline::separation(3.0);
        $force = $behavior->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            ['neighbors' => []],
        );

        $this->assertEqualsWithDelta(0.0, $force->length(), 1e-6);
    }

    public function testSeparationIgnoresDistantNeighbors(): void
    {
        $behavior = SteeringPipeline::separation(3.0);
        $force = $behavior->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            ['neighbors' => [new Vec3(100, 0, 0)]],
        );

        $this->assertEqualsWithDelta(0.0, $force->length(), 1e-6);
    }

    public function testPathFollowSteersTowardPathTarget(): void
    {
        $behavior = SteeringPipeline::pathFollow();
        $force = $behavior->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            ['pathTarget' => new Vec3(0, 0, 10)],
        );

        $this->assertGreaterThan(0.0, $force->z);
    }

    public function testPathFollowReturnsZeroWithoutTarget(): void
    {
        $behavior = SteeringPipeline::pathFollow();
        $force = $behavior->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            [],
        );

        $this->assertEqualsWithDelta(0.0, $force->length(), 1e-6);
    }

    public function testPipelineCombinesBehaviors(): void
    {
        $pipeline = new SteeringPipeline();
        $pipeline->add(SteeringPipeline::seek(new Vec3(10, 0, 0)), 1.0, 0);

        $force = $pipeline->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            [],
        );

        $this->assertGreaterThan(0.0, $force->length());
    }

    public function testPipelineRespectsPriority(): void
    {
        $pipeline = new SteeringPipeline();
        // High priority: flee from (10,0,0) - pushes -X
        $pipeline->add(SteeringPipeline::flee(new Vec3(10, 0, 0)), 1.0, 10);
        // Low priority: seek toward (10,0,0) - pushes +X
        $pipeline->add(SteeringPipeline::seek(new Vec3(10, 0, 0)), 1.0, 0);

        $force = $pipeline->calculate(
            new Vec3(0, 0, 0),
            Vec3::zero(),
            5.0,
            [],
        );

        // High-priority flee should dominate
        $this->assertLessThan(0.0, $force->x);
    }

    public function testClearRemovesBehaviors(): void
    {
        $pipeline = new SteeringPipeline();
        $pipeline->add(SteeringPipeline::seek(new Vec3(10, 0, 0)));
        $pipeline->clear();

        $force = $pipeline->calculate(Vec3::zero(), Vec3::zero(), 5.0, []);
        $this->assertEqualsWithDelta(0.0, $force->length(), 1e-6);
    }
}
