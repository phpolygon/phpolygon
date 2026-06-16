<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\Triangle;

class BVHTest extends TestCase
{
    /**
     * A small axis-aligned triangle centred near (x, 0, 0).
     */
    private function triAt(float $x): Triangle
    {
        return new Triangle(
            new Vec3($x - 0.1, 0, 0),
            new Vec3($x + 0.1, 0, 0),
            new Vec3($x, 0.2, 0),
        );
    }

    public function testEmptyBuildHasNoTriangles(): void
    {
        $bvh = BVH::build([]);
        $this->assertSame(0, $bvh->triangleCount());
        $this->assertSame([], $bvh->query(new Vec3(-1, -1, -1), new Vec3(1, 1, 1)));
    }

    public function testLeafReturnsAllTrianglesOnOverlap(): void
    {
        // <= LEAF_THRESHOLD (8) triangles => single leaf node.
        $tris = [$this->triAt(0), $this->triAt(1), $this->triAt(2)];
        $bvh = BVH::build($tris);

        $this->assertSame(3, $bvh->triangleCount());
        $result = $bvh->query(new Vec3(-5, -5, -5), new Vec3(5, 5, 5));
        $this->assertCount(3, $result);
    }

    public function testQueryMissReturnsEmpty(): void
    {
        $tris = [$this->triAt(0), $this->triAt(1)];
        $bvh = BVH::build($tris);
        // Query box far away from any triangle.
        $this->assertSame([], $bvh->query(new Vec3(100, 100, 100), new Vec3(101, 101, 101)));
    }

    public function testTriangleCountSurvivesInternalSplit(): void
    {
        // > LEAF_THRESHOLD triangles spread along X forces an internal split.
        $tris = [];
        for ($i = 0; $i < 20; $i++) {
            $tris[] = $this->triAt((float)$i);
        }
        $bvh = BVH::build($tris);
        $this->assertSame(20, $bvh->triangleCount());
    }

    public function testQueryOnSplitReturnsOnlyOverlappingRegion(): void
    {
        // 20 triangles spread far apart along X => internal nodes.
        $tris = [];
        for ($i = 0; $i < 20; $i++) {
            $tris[] = $this->triAt((float)$i * 10.0);
        }
        $bvh = BVH::build($tris);

        // Query a tight box around the triangle at x=0 only.
        $result = $bvh->query(new Vec3(-1, -1, -1), new Vec3(1, 1, 1));
        $this->assertNotEmpty($result);
        // The leaf bucketing may return a few neighbours, but never the whole set.
        $this->assertLessThan(20, count($result));

        // The triangle at x=0 must be present in the overlapping results.
        $foundOrigin = false;
        foreach ($result as $tri) {
            if (abs($tri->v2->x - 0.0) < 1e-6) {
                $foundOrigin = true;
                break;
            }
        }
        $this->assertTrue($foundOrigin, 'Triangle at x=0 should be in the query result');
    }

    public function testQueryAcrossWholeBoundsReturnsEverything(): void
    {
        $tris = [];
        for ($i = 0; $i < 20; $i++) {
            $tris[] = $this->triAt((float)$i * 10.0);
        }
        $bvh = BVH::build($tris);

        // A box covering all triangles must return all 20.
        $result = $bvh->query(new Vec3(-5, -5, -5), new Vec3(200, 5, 5));
        $this->assertCount(20, $result);
    }
}
