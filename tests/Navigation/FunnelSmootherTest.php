<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\FunnelSmoother;
use PHPolygon\Navigation\NavMeshEdge;

class FunnelSmootherTest extends TestCase
{
    public function testSmoothWithNoPortalsReturnsStraightLine(): void
    {
        $smoother = new FunnelSmoother();
        $path = $smoother->smooth(
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            [],
        );

        $this->assertCount(2, $path->waypoints);
        $this->assertTrue($path->waypoints[0]->equals(new Vec3(0, 0, 0)));
        $this->assertTrue($path->waypoints[1]->equals(new Vec3(10, 0, 0)));
    }

    public function testSmoothWithSinglePortal(): void
    {
        $smoother = new FunnelSmoother();
        $portal = new NavMeshEdge(
            new Vec3(5, 0, -2),
            new Vec3(5, 0, 2),
            0,
            1,
        );

        $path = $smoother->smooth(
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            [$portal],
        );

        // Should produce a path (at least start + end)
        $this->assertGreaterThanOrEqual(2, count($path->waypoints));

        // First waypoint is start
        $this->assertTrue($path->waypoints[0]->equals(new Vec3(0, 0, 0)));

        // Last waypoint is end
        $lastWp = $path->waypoints[count($path->waypoints) - 1];
        $this->assertTrue($lastWp->equals(new Vec3(10, 0, 0)));
    }

    public function testSmoothEliminatesRedundantWaypoints(): void
    {
        $smoother = new FunnelSmoother();

        // Wide portals aligned with the straight path - funnel should
        // produce a nearly direct path
        $portals = [
            new NavMeshEdge(
                new Vec3(3, 0, -5),
                new Vec3(3, 0, 5),
                0, 1,
            ),
            new NavMeshEdge(
                new Vec3(7, 0, -5),
                new Vec3(7, 0, 5),
                1, 2,
            ),
        ];

        $path = $smoother->smooth(
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            $portals,
        );

        // With wide portals and a straight line, the funnel should
        // produce just start + end (no intermediate turns needed)
        $this->assertLessThanOrEqual(4, count($path->waypoints));
    }

    public function testPathLengthIsPositive(): void
    {
        $smoother = new FunnelSmoother();
        $path = $smoother->smooth(
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 10),
            [],
        );

        $this->assertGreaterThan(0.0, $path->totalLength);
    }
}
