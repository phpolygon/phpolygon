<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Component\NavMeshAgent;
use PHPolygon\Navigation\NavMeshPath;

class NavMeshAgentTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $agent = new NavMeshAgent();

        $this->assertEqualsWithDelta(3.5, $agent->speed, 1e-6);
        $this->assertEqualsWithDelta(120.0, $agent->angularSpeed, 1e-6);
        $this->assertFalse($agent->hasPath);
        $this->assertFalse($agent->isStopped);
        $this->assertNull($agent->destination);
    }

    public function testSetDestinationMarksPathPending(): void
    {
        $agent = new NavMeshAgent();
        $agent->setDestination(new Vec3(10, 0, 10));

        $this->assertTrue($agent->isPathPending);
        $this->assertFalse($agent->isStopped);
        $this->assertNotNull($agent->destination);
        $this->assertEqualsWithDelta(10.0, $agent->destination->x, 1e-6);
    }

    public function testStopClearsState(): void
    {
        $agent = new NavMeshAgent();
        $agent->setDestination(new Vec3(10, 0, 10));
        $agent->currentPath = new NavMeshPath([new Vec3(0, 0, 0), new Vec3(10, 0, 10)]);
        $agent->hasPath = true;

        $agent->stop();

        $this->assertTrue($agent->isStopped);
        $this->assertFalse($agent->hasPath);
        $this->assertNull($agent->destination);
        $this->assertNull($agent->currentPath);
        $this->assertEqualsWithDelta(0.0, $agent->desiredVelocity->length(), 1e-6);
    }

    public function testRemainingDistanceWithPath(): void
    {
        $agent = new NavMeshAgent();
        $agent->currentPath = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(3, 0, 0),
            new Vec3(3, 0, 4),
        ]);
        $agent->currentWaypointIndex = 0;

        $this->assertEqualsWithDelta(7.0, $agent->remainingDistance(), 1e-6);
    }

    public function testRemainingDistanceWithoutPath(): void
    {
        $agent = new NavMeshAgent();
        $this->assertEqualsWithDelta(0.0, $agent->remainingDistance(), 1e-6);
    }

    public function testHasReachedDestination(): void
    {
        $agent = new NavMeshAgent(stoppingDistance: 1.0);
        $agent->currentPath = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(0.5, 0, 0),
        ]);
        $agent->currentWaypointIndex = 1;
        $agent->hasPath = true;

        $this->assertTrue($agent->hasReachedDestination());
    }

    public function testHasNotReachedDestination(): void
    {
        $agent = new NavMeshAgent(stoppingDistance: 0.5);
        $agent->currentPath = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
        ]);
        $agent->currentWaypointIndex = 0;
        $agent->hasPath = true;

        $this->assertFalse($agent->hasReachedDestination());
    }
}
