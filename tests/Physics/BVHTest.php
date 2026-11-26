<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\CollisionMath;
use PHPolygon\Tests\Physics\Fixtures\LegacyBvh;
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

            // Triangles are made on demand, so they are told apart by their corners (all distinct here).
            $ids = array_map(self::corners(...), $bvh->query($min, $max));
            $this->assertSame(count($ids), count(array_unique($ids)), 'no triangle twice');
            $found = array_flip($ids);
            foreach ($tris as $tri) {
                if ($this->overlaps($tri, $min, $max)) {
                    $this->assertArrayHasKey(self::corners($tri), $found, "query {$q} misses an overlapping triangle");
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

    private static function corners(Triangle $t): string
    {
        return sprintf('%.17g,%.17g,%.17g|%.17g,%.17g,%.17g|%.17g,%.17g,%.17g',
            $t->v0->x, $t->v0->y, $t->v0->z, $t->v1->x, $t->v1->y, $t->v1->z, $t->v2->x, $t->v2->y, $t->v2->z);
    }

    /**
     * @return list<Triangle>
     */
    private static function soup(int $count, int $seed): array
    {
        mt_srand($seed);
        $tris = [];
        for ($i = 0; $i < $count; $i++) {
            $x = mt_rand(-5000, 5000) / 100;
            $y = mt_rand(-500, 500) / 100;
            $z = mt_rand(-5000, 5000) / 100;
            $tris[] = new Triangle(
                new Vec3($x, $y, $z),
                new Vec3($x + mt_rand(1, 400) / 100, $y + mt_rand(-100, 100) / 100, $z + mt_rand(-50, 50) / 100),
                new Vec3($x + mt_rand(-50, 50) / 100, $y + mt_rand(1, 100) / 100, $z + mt_rand(1, 400) / 100),
            );
        }

        return $tris;
    }

    /** Same triangles in the same order as the object-per-triangle tree it replaced, on random soups. */
    public function testQueriesMatchTheObjectTreeExactly(): void
    {
        foreach ([[5, 1], [9, 2], [400, 3], [2500, 4]] as [$n, $seed]) {
            $tris = self::soup($n, $seed);
            $bvh = BVH::build($tris);
            $legacy = LegacyBvh::build($tris);
            $this->assertSame($legacy->triangleCount(), $bvh->triangleCount());

            mt_srand($seed + 100);
            for ($q = 0; $q < 150; $q++) {
                $cx = mt_rand(-5500, 5500) / 100;
                $cz = mt_rand(-5500, 5500) / 100;
                $hx = mt_rand(1, 1500) / 100;
                $min = new Vec3($cx - $hx, mt_rand(-700, 0) / 100, $cz - mt_rand(1, 1500) / 100);
                $max = new Vec3($cx + $hx, mt_rand(0, 700) / 100, $cz + mt_rand(1, 1500) / 100);
                $this->assertSame(
                    array_map(self::corners(...), $legacy->query($min, $max)),
                    array_map(self::corners(...), $bvh->query($min, $max)),
                    "soup {$n}, query {$q}",
                );
            }
        }
    }

    /** Ray (segment) and capsule answers over the returned triangles, against the object tree. */
    public function testSegmentAndCapsuleAnswersMatchTheObjectTree(): void
    {
        $tris = self::soup(1500, 11);
        $bvh = BVH::build($tris);
        $legacy = LegacyBvh::build($tris);

        mt_srand(12);
        $segHits = 0;
        for ($q = 0; $q < 200; $q++) {
            $from = new Vec3(mt_rand(-4000, 4000) / 100, mt_rand(-400, 400) / 100, mt_rand(-4000, 4000) / 100);
            $to = new Vec3($from->x + mt_rand(-1500, 1500) / 100, $from->y + mt_rand(-300, 300) / 100, $from->z + mt_rand(-1500, 1500) / 100);
            $min = new Vec3(min($from->x, $to->x), min($from->y, $to->y), min($from->z, $to->z));
            $max = new Vec3(max($from->x, $to->x), max($from->y, $to->y), max($from->z, $to->z));
            $a = array_map(static fn (Triangle $t): bool => CollisionMath::segmentIntersectsTriangle($from, $to, $t), $legacy->query($min, $max));
            $b = array_map(static fn (Triangle $t): bool => CollisionMath::segmentIntersectsTriangle($from, $to, $t), $bvh->query($min, $max));
            $this->assertSame($a, $b);
            $segHits += count(array_filter($b));

            $segA = new Vec3($from->x, $from->y, $from->z);
            $segB = new Vec3($from->x, $from->y + 1.2, $from->z);
            $cmin = new Vec3($from->x - 0.5, $from->y - 0.5, $from->z - 0.5);
            $cmax = new Vec3($from->x + 0.5, $from->y + 1.7, $from->z + 0.5);
            $push = static fn (Triangle $t): ?array => ($p = CollisionMath::capsuleVsTriangle($segA, $segB, 0.4, $t)) === null ? null : [$p->x, $p->y, $p->z];
            $this->assertSame(array_map($push, $legacy->query($cmin, $cmax)), array_map($push, $bvh->query($cmin, $cmax)));
        }
        $this->assertGreaterThan(0, $segHits, 'the soup is dense enough for the comparison to see hits');
    }

    /** fromMesh with a world matrix and the degenerate filter is the old "transform every triangle, drop the flat ones, build". */
    public function testFromMeshInWorldSpaceMatchesTransformThenBuild(): void
    {
        mt_srand(21);
        $vertices = [];
        for ($i = 0; $i < 900; $i++) {
            array_push($vertices, mt_rand(-1000, 1000) / 100, mt_rand(-300, 300) / 100, mt_rand(-1000, 1000) / 100);
        }
        $indices = [];
        for ($i = 0; $i < 1500; $i++) {
            array_push($indices, mt_rand(0, 899), mt_rand(0, 899), mt_rand(0, 899));
        }
        // A few collapsed triangles (shared corner, or all three the same).
        array_push($indices, 5, 5, 6, 7, 7, 7, 3, 4, 3);
        $world = Mat4::trs(new Vec3(3.0, -1.0, 7.5), Quaternion::fromEuler(0.2, 1.1, -0.3), new Vec3(1.5, 0.8, 2.0));

        $tris = [];
        for ($i = 0; $i < count($indices); $i += 3) {
            $p = static fn (int $v): Vec3 => $world->transformPoint(new Vec3($vertices[$v * 3], $vertices[$v * 3 + 1], $vertices[$v * 3 + 2]));
            $t = new Triangle($p($indices[$i]), $p($indices[$i + 1]), $p($indices[$i + 2]));
            if (!$t->isDegenerate()) {
                $tris[] = $t;
            }
        }
        $legacy = LegacyBvh::build($tris);
        $bvh = BVH::fromMesh($vertices, $indices, $world, true);
        $this->assertSame($legacy->triangleCount(), $bvh->triangleCount());
        $this->assertLessThan(count($indices) / 3, $bvh->triangleCount(), 'degenerate triangles were dropped');

        for ($q = 0; $q < 100; $q++) {
            $c = new Vec3(mt_rand(-2500, 2500) / 100, mt_rand(-800, 800) / 100, mt_rand(-2500, 2500) / 100);
            $h = mt_rand(50, 800) / 100;
            $min = new Vec3($c->x - $h, $c->y - $h, $c->z - $h);
            $max = new Vec3($c->x + $h, $c->y + $h, $c->z + $h);
            $this->assertSame(
                array_map(self::corners(...), $legacy->query($min, $max)),
                array_map(self::corners(...), $bvh->query($min, $max)),
            );
        }

        // Without the filter every triangle is kept.
        $this->assertSame(count($indices) / 3, BVH::fromMesh($vertices, $indices, $world)->triangleCount());
    }

    public function testDeferredBvhBuildsOnFirstQueryThatReachesItsBound(): void
    {
        $tris = self::soup(300, 5);
        $built = 0;
        $bvh = BVH::deferred(static function () use ($tris, &$built): BVH {
            $built++;

            return BVH::build($tris);
        }, new Vec3(-60, -10, -60), new Vec3(60, 10, 60));

        $this->assertFalse($bvh->isBuilt());
        $this->assertSame([], $bvh->query(new Vec3(100, -1, 100), new Vec3(101, 1, 101)), 'outside the bound');
        $this->assertSame(0, $built, 'a miss builds nothing');

        $min = new Vec3(-20, -10, -20);
        $max = new Vec3(20, 10, 20);
        $expected = array_map(self::corners(...), BVH::build($tris)->query($min, $max));
        $this->assertSame($expected, array_map(self::corners(...), $bvh->query($min, $max)));
        $this->assertSame(1, $built);
        $this->assertTrue($bvh->isBuilt());
        $bvh->query($min, $max);
        $this->assertSame(1, $built, 'built once');
        $this->assertSame(300, $bvh->triangleCount());
    }

    public function testDeferredBvhCountBuildsIt(): void
    {
        $bvh = BVH::deferred(static fn (): BVH => BVH::build(self::soup(40, 6)), new Vec3(-1, -1, -1), new Vec3(1, 1, 1));
        $this->assertSame(40, $bvh->triangleCount());
        $this->assertTrue($bvh->isBuilt());
    }

    public function testTriangleCacheBudgetIsGlobalAndResultsSurviveAFlush(): void
    {
        $trees = [];
        for ($i = 0; $i < 4; $i++) {
            $trees[] = BVH::build(self::soup(9000, 30 + $i));
        }
        $min = new Vec3(-100, -20, -100);
        $max = new Vec3(100, 20, 100);
        $expected = array_map(fn (BVH $t): array => array_map(self::corners(...), $t->query($min, $max)), $trees);
        // 36,000 objects were made, over the 20,000 budget: the next queries start a new generation.
        for ($round = 0; $round < 3; $round++) {
            foreach ($trees as $i => $tree) {
                $this->assertSame($expected[$i], array_map(self::corners(...), $tree->query($min, $max)), "tree {$i}, round {$round}");
            }
        }
    }

    public function testTheSameTriangleComesBackAsTheSameObject(): void
    {
        $bvh = BVH::build(self::soup(100, 8));
        $min = new Vec3(-60, -10, -60);
        $max = new Vec3(60, 10, 60);
        $first = $bvh->query($min, $max);
        $second = $bvh->query($min, $max);
        $this->assertNotEmpty($first);
        foreach ($first as $i => $t) {
            $this->assertSame($t, $second[$i]);
        }
    }

    /** What the packing is for: a BVH of 20,000 triangles stays far below an object per triangle. */
    public function testRetainedMemoryPerTriangle(): void
    {
        $side = 100;
        $vertices = [];
        for ($z = 0; $z <= $side; $z++) {
            for ($x = 0; $x <= $side; $x++) {
                array_push($vertices, (float) $x, sin($x * 0.3) * cos($z * 0.2), (float) $z);
            }
        }
        $indices = [];
        for ($z = 0; $z < $side; $z++) {
            for ($x = 0; $x < $side; $x++) {
                $i = $z * ($side + 1) + $x;
                array_push($indices, $i, $i + $side + 1, $i + 1, $i + 1, $i + $side + 1, $i + $side + 2);
            }
        }
        $count = intdiv(count($indices), 3);

        gc_collect_cycles();
        $before = memory_get_usage();
        $bvh = BVH::fromMesh($vertices, $indices);
        gc_collect_cycles();
        $perTriangle = (memory_get_usage() - $before) / $count;

        $this->assertSame($count, $bvh->triangleCount());
        $this->assertLessThan(250, $perTriangle, "BVH holds {$perTriangle} bytes per triangle");
    }
}