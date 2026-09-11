<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\Physics;

use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\Triangle;

/**
 * Collider BVH build over a 20k-triangle height grid – the shape of a large
 * imported mesh collider – plus the steady-state query cost.
 *
 * benchLegacyBuild reproduces the previous object tree inline (usort with a
 * callback that allocates a centroid Vec3 per comparison, array_slice per
 * split) so the comparison runs on the same machine in the same process.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/Physics/BVHBench.php --report=aggregate
 */
final class BVHBench
{
    private const int COLS = 101;
    private const int ROWS = 101;

    /** @var list<float> */
    private array $vertices = [];

    /** @var list<int> */
    private array $indices = [];

    /** @var list<Triangle> */
    private array $triangles = [];

    private BVH $bvh;

    public function setUp(): void
    {
        $this->vertices = [];
        $this->indices = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                array_push($this->vertices, (float) $c, sin($c * 0.21) * cos($r * 0.17) * 2.0, (float) $r);
            }
        }
        for ($r = 0; $r < self::ROWS - 1; $r++) {
            for ($c = 0; $c < self::COLS - 1; $c++) {
                $i = $r * self::COLS + $c;
                array_push($this->indices, $i, $i + self::COLS, $i + 1, $i + 1, $i + self::COLS, $i + self::COLS + 1);
            }
        }
        $this->triangles = [];
        $v = $this->vertices;
        for ($i = 0, $n = count($this->indices); $i < $n; $i += 3) {
            $a = $this->indices[$i] * 3;
            $b = $this->indices[$i + 1] * 3;
            $c = $this->indices[$i + 2] * 3;
            $this->triangles[] = new Triangle(
                new Vec3($v[$a], $v[$a + 1], $v[$a + 2]),
                new Vec3($v[$b], $v[$b + 1], $v[$b + 2]),
                new Vec3($v[$c], $v[$c + 1], $v[$c + 2]),
            );
        }
        $this->bvh = BVH::build($this->triangles);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1)
     * @Iterations(3)
     */
    public function benchLegacyBuild(): void
    {
        self::legacyBuild($this->triangles);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1)
     * @Iterations(5)
     */
    public function benchBuildFromTriangles(): void
    {
        BVH::build($this->triangles);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(1)
     * @Iterations(5)
     */
    public function benchFromMesh(): void
    {
        BVH::fromMesh($this->vertices, $this->indices);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(200)
     * @Iterations(5)
     */
    public function benchQueryCharacterBox(): void
    {
        $this->bvh->query(new Vec3(49.6, -3.0, 49.6), new Vec3(50.4, 3.0, 50.4));
    }

    /**
     * The previous BVH::build, reduced to its cost: sort by centroid with a
     * callback, split with array_slice, recurse.
     *
     * @param list<Triangle> $triangles
     */
    private static function legacyBuild(array $triangles): int
    {
        if (count($triangles) <= 8) {
            return 1;
        }
        $minX = $minY = $minZ = PHP_FLOAT_MAX;
        $maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;
        foreach ($triangles as $tri) {
            foreach ([$tri->v0, $tri->v1, $tri->v2] as $p) {
                $minX = min($minX, $p->x);
                $minY = min($minY, $p->y);
                $minZ = min($minZ, $p->z);
                $maxX = max($maxX, $p->x);
                $maxY = max($maxY, $p->y);
                $maxZ = max($maxZ, $p->z);
            }
        }
        $ex = $maxX - $minX;
        $ey = $maxY - $minY;
        $ez = $maxZ - $minZ;
        $axis = ($ex >= $ey && $ex >= $ez) ? 0 : ($ey >= $ez ? 1 : 2);
        usort($triangles, static function (Triangle $a, Triangle $b) use ($axis): int {
            $ca = new Vec3(($a->v0->x + $a->v1->x + $a->v2->x) / 3.0, ($a->v0->y + $a->v1->y + $a->v2->y) / 3.0, ($a->v0->z + $a->v1->z + $a->v2->z) / 3.0);
            $cb = new Vec3(($b->v0->x + $b->v1->x + $b->v2->x) / 3.0, ($b->v0->y + $b->v1->y + $b->v2->y) / 3.0, ($b->v0->z + $b->v1->z + $b->v2->z) / 3.0);
            return match ($axis) { 0 => $ca->x <=> $cb->x, 1 => $ca->y <=> $cb->y, default => $ca->z <=> $cb->z };
        });
        $mid = intdiv(count($triangles), 2);
        return 1 + self::legacyBuild(array_slice($triangles, 0, $mid)) + self::legacyBuild(array_slice($triangles, $mid));
    }
}
