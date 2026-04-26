<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\NavMeshPath;

class NavMeshPathTest extends TestCase
{
    public function testTotalLengthComputedCorrectly(): void
    {
        $path = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(3, 0, 0),
            new Vec3(3, 0, 4),
        ]);

        // 3 + 4 = 7
        $this->assertEqualsWithDelta(7.0, $path->totalLength, 1e-6);
    }

    public function testEmptyPathHasZeroLength(): void
    {
        $path = new NavMeshPath([]);
        $this->assertEqualsWithDelta(0.0, $path->totalLength, 1e-6);
    }

    public function testGetPointAtDistanceInterpolatesCorrectly(): void
    {
        $path = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
        ]);

        $mid = $path->getPointAtDistance(5.0);
        $this->assertEqualsWithDelta(5.0, $mid->x, 1e-6);
        $this->assertEqualsWithDelta(0.0, $mid->z, 1e-6);
    }

    public function testGetPointAtDistanceReturnsStartForZero(): void
    {
        $path = new NavMeshPath([
            new Vec3(1, 0, 0),
            new Vec3(10, 0, 0),
        ]);

        $point = $path->getPointAtDistance(0.0);
        $this->assertEqualsWithDelta(1.0, $point->x, 1e-6);
    }

    public function testGetPointAtDistanceBeyondEndReturnsLastWaypoint(): void
    {
        $path = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(5, 0, 0),
        ]);

        $point = $path->getPointAtDistance(100.0);
        $this->assertEqualsWithDelta(5.0, $point->x, 1e-6);
    }

    public function testGetPointAtDistanceMultiSegment(): void
    {
        $path = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(3, 0, 0),
            new Vec3(3, 0, 4),
        ]);

        // Distance 5 = 3 along first segment + 2 along second
        $point = $path->getPointAtDistance(5.0);
        $this->assertEqualsWithDelta(3.0, $point->x, 1e-6);
        $this->assertEqualsWithDelta(2.0, $point->z, 1e-6);
    }

    public function testRemainingDistance(): void
    {
        $path = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(3, 0, 0),
            new Vec3(3, 0, 4),
        ]);

        $this->assertEqualsWithDelta(7.0, $path->remainingDistance(0), 1e-6);
        $this->assertEqualsWithDelta(4.0, $path->remainingDistance(1), 1e-6);
        $this->assertEqualsWithDelta(0.0, $path->remainingDistance(2), 1e-6);
    }

    public function testSerializationRoundtrip(): void
    {
        $path = new NavMeshPath(
            [new Vec3(1, 2, 3), new Vec3(4, 5, 6)],
            [0, 1],
            12.5,
            true,
        );

        $restored = NavMeshPath::fromArray($path->toArray());

        $this->assertCount(2, $restored->waypoints);
        $this->assertTrue($restored->waypoints[0]->equals(new Vec3(1, 2, 3)));
        $this->assertSame([0, 1], $restored->polygonIds);
        $this->assertEqualsWithDelta(12.5, $restored->totalCost, 1e-6);
        $this->assertTrue($restored->isPartial);
    }

    public function testWaypointCount(): void
    {
        $path = new NavMeshPath([
            new Vec3(0, 0, 0),
            new Vec3(1, 0, 0),
            new Vec3(2, 0, 0),
        ]);

        $this->assertSame(3, $path->waypointCount());
    }
}
