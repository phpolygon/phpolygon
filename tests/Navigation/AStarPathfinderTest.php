<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\AStarPathfinder;
use PHPolygon\Navigation\NavMesh;
use PHPolygon\Navigation\NavMeshEdge;
use PHPolygon\Navigation\NavMeshPolygon;

class AStarPathfinderTest extends TestCase
{
    /**
     * Build a 3-polygon strip:
     *  [0] -- [1] -- [2]
     * Each polygon is a triangle, adjacent polygons share an edge.
     */
    private function createStripMesh(): NavMesh
    {
        $mesh = new NavMesh();

        // Polygon 0: triangle at x=[0,5]
        $mesh->addPolygon(new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(5, 0, 0),
            new Vec3(2.5, 0, 5),
        ], [1], [5.0]));

        // Polygon 1: triangle at x=[5,10]
        $mesh->addPolygon(new NavMeshPolygon(1, [
            new Vec3(5, 0, 0),
            new Vec3(10, 0, 0),
            new Vec3(7.5, 0, 5),
        ], [0, 2], [5.0, 5.0]));

        // Polygon 2: triangle at x=[10,15]
        $mesh->addPolygon(new NavMeshPolygon(2, [
            new Vec3(10, 0, 0),
            new Vec3(15, 0, 0),
            new Vec3(12.5, 0, 5),
        ], [1], [5.0]));

        // Edges
        $mesh->addEdge(new NavMeshEdge(
            new Vec3(5, 0, 0), new Vec3(2.5, 0, 5), 0, 1,
        ));
        $mesh->addEdge(new NavMeshEdge(
            new Vec3(10, 0, 0), new Vec3(7.5, 0, 5), 1, 2,
        ));

        return $mesh;
    }

    public function testPathWithinSamePolygon(): void
    {
        $mesh = $this->createStripMesh();
        $pathfinder = new AStarPathfinder();

        $path = $pathfinder->findPath(
            $mesh, 0, 0,
            new Vec3(1, 0, 1),
            new Vec3(3, 0, 1),
        );

        $this->assertNotNull($path);
        $this->assertSame([0], $path->polygonIds);
        $this->assertCount(2, $path->waypoints);
    }

    public function testPathAcrossAdjacentPolygons(): void
    {
        $mesh = $this->createStripMesh();
        $pathfinder = new AStarPathfinder();

        $path = $pathfinder->findPath(
            $mesh, 0, 1,
            new Vec3(1, 0, 1),
            new Vec3(8, 0, 1),
        );

        $this->assertNotNull($path);
        $this->assertSame([0, 1], $path->polygonIds);
        $this->assertGreaterThanOrEqual(2, count($path->waypoints));
    }

    public function testPathAcrossMultiplePolygons(): void
    {
        $mesh = $this->createStripMesh();
        $pathfinder = new AStarPathfinder();

        $path = $pathfinder->findPath(
            $mesh, 0, 2,
            new Vec3(1, 0, 1),
            new Vec3(13, 0, 1),
        );

        $this->assertNotNull($path);
        $this->assertSame([0, 1, 2], $path->polygonIds);
        $this->assertGreaterThanOrEqual(2, count($path->waypoints));
        // First waypoint should be start, last should be end
        $this->assertTrue($path->waypoints[0]->equals(new Vec3(1, 0, 1)));
        $wp = $path->waypoints[count($path->waypoints) - 1];
        $this->assertTrue($wp->equals(new Vec3(13, 0, 1)));
    }

    public function testNoPathToDisconnectedPolygon(): void
    {
        $mesh = new NavMesh();
        $mesh->addPolygon(new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(5, 0, 0),
            new Vec3(0, 0, 5),
        ]));
        $mesh->addPolygon(new NavMeshPolygon(1, [
            new Vec3(100, 0, 100),
            new Vec3(105, 0, 100),
            new Vec3(100, 0, 105),
        ]));
        // No edges - disconnected

        $pathfinder = new AStarPathfinder();
        $path = $pathfinder->findPath(
            $mesh, 0, 1,
            new Vec3(1, 0, 1),
            new Vec3(101, 0, 101),
        );

        $this->assertNull($path);
    }

    public function testNoPathToNonExistentPolygon(): void
    {
        $mesh = $this->createStripMesh();
        $pathfinder = new AStarPathfinder();

        $path = $pathfinder->findPath(
            $mesh, 0, 99,
            new Vec3(1, 0, 1),
            new Vec3(50, 0, 50),
        );

        $this->assertNull($path);
    }

    public function testPathTotalCostIsPositive(): void
    {
        $mesh = $this->createStripMesh();
        $pathfinder = new AStarPathfinder();

        $path = $pathfinder->findPath(
            $mesh, 0, 2,
            new Vec3(1, 0, 1),
            new Vec3(13, 0, 1),
        );

        $this->assertNotNull($path);
        $this->assertGreaterThan(0.0, $path->totalCost);
    }
}
