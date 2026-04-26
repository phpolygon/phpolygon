<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\NavMeshPolygon;

class NavMeshPolygonTest extends TestCase
{
    public function testCentroidIsAverageOfVertices(): void
    {
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(6, 0, 0),
            new Vec3(3, 0, 6),
        ]);

        $this->assertEqualsWithDelta(3.0, $poly->centroid->x, 1e-6);
        $this->assertEqualsWithDelta(0.0, $poly->centroid->y, 1e-6);
        $this->assertEqualsWithDelta(2.0, $poly->centroid->z, 1e-6);
    }

    public function testAreaOfTriangle(): void
    {
        // Right triangle with legs 4 and 3 -> area = 6
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(4, 0, 0),
            new Vec3(0, 0, 3),
        ]);

        $this->assertEqualsWithDelta(6.0, $poly->area, 1e-6);
    }

    public function testAreaOfQuad(): void
    {
        // 2x2 quad -> area = 4
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(2, 0, 0),
            new Vec3(2, 0, 2),
            new Vec3(0, 0, 2),
        ]);

        $this->assertEqualsWithDelta(4.0, $poly->area, 1e-6);
    }

    public function testContainsPointXZInsideTriangle(): void
    {
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            new Vec3(0, 0, 10),
        ]);

        $this->assertTrue($poly->containsPointXZ(new Vec3(2, 5, 2)));
        $this->assertTrue($poly->containsPointXZ(new Vec3(1, 0, 1)));
    }

    public function testContainsPointXZOutsideTriangle(): void
    {
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            new Vec3(0, 0, 10),
        ]);

        $this->assertFalse($poly->containsPointXZ(new Vec3(8, 0, 8)));
        $this->assertFalse($poly->containsPointXZ(new Vec3(-1, 0, 0)));
    }

    public function testContainsPointXZIgnoresY(): void
    {
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            new Vec3(0, 0, 10),
        ]);

        // Point is inside XZ projection regardless of Y
        $this->assertTrue($poly->containsPointXZ(new Vec3(2, 100, 2)));
    }

    public function testGetSharedEdgeFindsCommonVertices(): void
    {
        $a = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(5, 0, 0),
            new Vec3(5, 0, 5),
        ]);
        $b = new NavMeshPolygon(1, [
            new Vec3(5, 0, 0),
            new Vec3(10, 0, 0),
            new Vec3(5, 0, 5),
        ]);

        $edge = $a->getSharedEdge($b);
        $this->assertNotNull($edge);
        $this->assertCount(2, $edge);
    }

    public function testGetSharedEdgeReturnsNullWhenNoSharedVertices(): void
    {
        $a = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(1, 0, 0),
            new Vec3(0, 0, 1),
        ]);
        $b = new NavMeshPolygon(1, [
            new Vec3(10, 0, 10),
            new Vec3(11, 0, 10),
            new Vec3(10, 0, 11),
        ]);

        $this->assertNull($a->getSharedEdge($b));
    }

    public function testSerializationRoundtrip(): void
    {
        $poly = new NavMeshPolygon(42, [
            new Vec3(1, 2, 3),
            new Vec3(4, 5, 6),
            new Vec3(7, 8, 9),
        ], [10, 20], [1.5, 2.5]);

        $restored = NavMeshPolygon::fromArray($poly->toArray());

        $this->assertSame(42, $restored->id);
        $this->assertCount(3, $restored->vertices);
        $this->assertTrue($restored->vertices[0]->equals(new Vec3(1, 2, 3)));
        $this->assertSame([10, 20], $restored->neighborIds);
        $this->assertSame([1.5, 2.5], $restored->edgeCosts);
    }

    public function testDegeneratePolygonHasZeroArea(): void
    {
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(1, 0, 0),
        ]);

        $this->assertEqualsWithDelta(0.0, $poly->area, 1e-6);
    }
}
