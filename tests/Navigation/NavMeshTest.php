<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\NavMesh;
use PHPolygon\Navigation\NavMeshEdge;
use PHPolygon\Navigation\NavMeshPolygon;

class NavMeshTest extends TestCase
{
    private function createSimpleMesh(): NavMesh
    {
        // Two adjacent triangles forming a quad:
        //  (0,0)---(5,0)---(10,0)
        //    |    /    |    /
        //    |   /     |   /
        //  (0,5)---(5,5)---(10,5)
        $mesh = new NavMesh(4.0);

        $p0 = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(5, 0, 0),
            new Vec3(0, 0, 5),
        ], [1], [5.0]);

        $p1 = new NavMeshPolygon(1, [
            new Vec3(5, 0, 0),
            new Vec3(5, 0, 5),
            new Vec3(0, 0, 5),
        ], [0], [5.0]);

        $mesh->addPolygon($p0);
        $mesh->addPolygon($p1);

        $mesh->addEdge(new NavMeshEdge(
            new Vec3(5, 0, 0),
            new Vec3(0, 0, 5),
            0,
            1,
        ));

        return $mesh;
    }

    public function testAddAndRetrievePolygon(): void
    {
        $mesh = new NavMesh();
        $poly = new NavMeshPolygon(0, [
            new Vec3(0, 0, 0),
            new Vec3(1, 0, 0),
            new Vec3(0, 0, 1),
        ]);
        $mesh->addPolygon($poly);

        $this->assertSame(1, $mesh->polygonCount());
        $this->assertSame($poly, $mesh->getPolygon(0));
        $this->assertNull($mesh->getPolygon(99));
    }

    public function testFindPolygonAtFindsCorrectPolygon(): void
    {
        $mesh = $this->createSimpleMesh();

        // Point inside first triangle (bottom-left half)
        $found = $mesh->findPolygonAt(new Vec3(1, 0, 1));
        $this->assertNotNull($found);
        $this->assertSame(0, $found->id);
    }

    public function testFindPolygonAtReturnsNullOutsideMesh(): void
    {
        $mesh = $this->createSimpleMesh();
        $this->assertNull($mesh->findPolygonAt(new Vec3(20, 0, 20)));
    }

    public function testFindNearestPolygon(): void
    {
        $mesh = $this->createSimpleMesh();

        // Point outside but nearest to polygon 1
        $found = $mesh->findNearestPolygon(new Vec3(6, 0, 6), 20.0);
        $this->assertNotNull($found);
    }

    public function testFindNearestPolygonReturnsNullBeyondMaxDistance(): void
    {
        $mesh = $this->createSimpleMesh();
        $this->assertNull($mesh->findNearestPolygon(new Vec3(100, 0, 100), 1.0));
    }

    public function testFindPolygonsInRadius(): void
    {
        $mesh = $this->createSimpleMesh();
        $results = $mesh->findPolygonsInRadius(new Vec3(2.5, 0, 2.5), 10.0);
        $this->assertCount(2, $results);
    }

    public function testGetEdgeBetween(): void
    {
        $mesh = $this->createSimpleMesh();
        $edge = $mesh->getEdgeBetween(0, 1);
        $this->assertNotNull($edge);
        $this->assertSame(0, $edge->polygonA);
        $this->assertSame(1, $edge->polygonB);
    }

    public function testGetEdgeBetweenReturnsNullForNonAdjacent(): void
    {
        $mesh = $this->createSimpleMesh();
        $this->assertNull($mesh->getEdgeBetween(0, 99));
    }

    public function testSerializationRoundtrip(): void
    {
        $mesh = $this->createSimpleMesh();
        $data = $mesh->toArray();
        $restored = NavMesh::fromArray($data);

        $this->assertSame(2, $restored->polygonCount());
        $this->assertNotNull($restored->getPolygon(0));
        $this->assertNotNull($restored->getPolygon(1));
        $this->assertNotNull($restored->getEdgeBetween(0, 1));
    }

    public function testBuildFromPolygonsAutoDetectsAdjacency(): void
    {
        $polys = [
            new NavMeshPolygon(0, [
                new Vec3(0, 0, 0),
                new Vec3(5, 0, 0),
                new Vec3(0, 0, 5),
            ]),
            new NavMeshPolygon(1, [
                new Vec3(5, 0, 0),
                new Vec3(5, 0, 5),
                new Vec3(0, 0, 5),
            ]),
        ];

        $mesh = NavMesh::buildFromPolygons($polys);

        // Auto-detected neighbors
        $p0 = $mesh->getPolygon(0);
        $this->assertNotNull($p0);
        $this->assertContains(1, $p0->neighborIds);

        $p1 = $mesh->getPolygon(1);
        $this->assertNotNull($p1);
        $this->assertContains(0, $p1->neighborIds);

        // Edge created
        $this->assertNotNull($mesh->getEdgeBetween(0, 1));
    }

    public function testProjectPoint(): void
    {
        $mesh = $this->createSimpleMesh();
        $projected = $mesh->projectPoint(new Vec3(1, 10, 1));
        $this->assertNotNull($projected);
        $this->assertEqualsWithDelta(0.0, $projected->y, 1e-6);
    }
}
