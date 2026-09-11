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

    /**
     * The contract every caller relies on: whatever a leaf adds, a triangle
     * that overlaps the query box is never missing, and none comes back twice.
     */
    public function testQueryReturnsEveryOverlappingTriangleExactlyOnce(): void
    {
        mt_srand(42);
        $tris = [];
        for ($i = 0; $i < 3000; $i++) {
            $x = mt_rand(-5000, 5000) / 100;
            $y = mt_rand(-500, 500) / 100;
            $z = mt_rand(-5000, 5000) / 100;
            $tris[] = new Triangle(
                new Vec3($x, $y, $z),
                new Vec3($x + mt_rand(1, 200) / 100, $y + mt_rand(-100, 100) / 100, $z),
                new Vec3($x, $y + mt_rand(1, 100) / 100, $z + mt_rand(1, 200) / 100),
            );
        }
        $bvh = BVH::build($tris);
        $this->assertSame(3000, $bvh->triangleCount());

        for ($q = 0; $q < 300; $q++) {
            $cx = mt_rand(-5000, 5000) / 100;
            $cz = mt_rand(-5000, 5000) / 100;
            $half = mt_rand(10, 600) / 100;
            $min = new Vec3($cx - $half, -2.0, $cz - $half);
            $max = new Vec3($cx + $half, 2.0, $cz + $half);

            $ids = array_map('spl_object_id', $bvh->query($min, $max));
            $this->assertSame(count($ids), count(array_unique($ids)), 'no triangle twice');
            $found = array_flip($ids);
            foreach ($tris as $tri) {
                if ($this->overlaps($tri, $min, $max)) {
                    $this->assertArrayHasKey(spl_object_id($tri), $found, "query {$q} misses an overlapping triangle");
                }
            }
        }
    }

    public function testFromMeshFindsTheSameTrianglesAsBuild(): void
    {
        // A 40 x 30 grid of quads in the XZ plane with a gentle height ripple.
        $vertices = [];
        $indices = [];
        $cols = 41;
        $rows = 31;
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                array_push($vertices, (float) $c, sin($c * 0.3) * 0.5, (float) $r);
            }
        }
        for ($r = 0; $r < $rows - 1; $r++) {
            for ($c = 0; $c < $cols - 1; $c++) {
                $i = $r * $cols + $c;
                array_push($indices, $i, $i + $cols, $i + 1, $i + 1, $i + $cols, $i + $cols + 1);
            }
        }

        $tris = [];
        for ($i = 0; $i < count($indices); $i += 3) {
            $p = static fn (int $v): Vec3 => new Vec3($vertices[$v * 3], $vertices[$v * 3 + 1], $vertices[$v * 3 + 2]);
            $tris[] = new Triangle($p($indices[$i]), $p($indices[$i + 1]), $p($indices[$i + 2]));
        }

        $fromMesh = BVH::fromMesh($vertices, $indices);
        $built = BVH::build($tris);
        $this->assertSame(count($tris), $fromMesh->triangleCount());

        $key = static fn (Triangle $t): string => sprintf(
            '%.4f,%.4f,%.4f|%.4f,%.4f,%.4f|%.4f,%.4f,%.4f',
            $t->v0->x, $t->v0->y, $t->v0->z, $t->v1->x, $t->v1->y, $t->v1->z, $t->v2->x, $t->v2->y, $t->v2->z,
        );
        foreach ([[2.5, 2.5, 1.0], [20.0, 15.0, 3.0], [39.5, 29.5, 0.4], [-5.0, -5.0, 1.0]] as [$x, $z, $h]) {
            $min = new Vec3($x - $h, -1.0, $z - $h);
            $max = new Vec3($x + $h, 1.0, $z + $h);
            $a = array_map($key, array_values(array_filter($built->query($min, $max), fn (Triangle $t): bool => $this->overlaps($t, $min, $max))));
            $b = array_map($key, array_values(array_filter($fromMesh->query($min, $max), fn (Triangle $t): bool => $this->overlaps($t, $min, $max))));
            sort($a);
            sort($b);
            $this->assertSame($a, $b, "box at ({$x}, {$z})");
        }
        $this->assertSame([], BVH::fromMesh([], [])->query(new Vec3(-1, -1, -1), new Vec3(1, 1, 1)));
    }

    public function testIdenticalCentroidsStillBuild(): void
    {
        $tris = [];
        for ($i = 0; $i < 50; $i++) {
            $tris[] = $this->triAt(0.0);
        }
        $bvh = BVH::build($tris);
        $this->assertSame(50, $bvh->triangleCount());
        $this->assertCount(50, $bvh->query(new Vec3(-1, -1, -1), new Vec3(1, 1, 1)));
    }

    private function overlaps(Triangle $t, Vec3 $min, Vec3 $max): bool
    {
        return max($t->v0->x, $t->v1->x, $t->v2->x) >= $min->x && min($t->v0->x, $t->v1->x, $t->v2->x) <= $max->x
            && max($t->v0->y, $t->v1->y, $t->v2->y) >= $min->y && min($t->v0->y, $t->v1->y, $t->v2->y) <= $max->y
            && max($t->v0->z, $t->v1->z, $t->v2->z) >= $min->z && min($t->v0->z, $t->v1->z, $t->v2->z) <= $max->z;
    }
}
