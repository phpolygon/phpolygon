<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use Closure;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Binary bounding volume hierarchy for fast triangle queries.
 *
 * Built top-down with a median split along the longest extent of the triangle
 * centroids; leaves hold at most {@see LEAF_THRESHOLD} triangles. The tree is
 * stored flat – node bounds, child indices and leaf ranges in plain arrays – so
 * building allocates no per-node objects and a query walks ints and floats.
 *
 * The triangles themselves are one packed string (nine doubles each, in leaf
 * order), not one object graph per triangle: a Triangle with its three Vec3 and
 * its normal costs about 600 bytes, the packed form 72, which is what a collider
 * of hundreds of thousands of triangles needs to fit in memory. {@see query()}
 * hands out Triangle objects as before, created on first use and kept in a
 * bounded cache, so the same triangle comes back as the same object while the
 * cache holds it. The values are the doubles that went in: results are
 * identical to a tree of the original objects.
 *
 * A BVH can also be {@see deferred()}: it knows only a conservative bound and
 * builds itself the first time a query reaches that bound, so a world full of
 * mesh colliders pays for the ones a character actually comes near.
 *
 * Each node's range is ordered once with array_multisort() over float keys (C,
 * no PHP callback); the ranges are passed down the recursion as id lists, so the
 * cost is O(n log n) (an in-place reorder of one big array was quadratic).
 */
class BVH
{
    private const int LEAF_THRESHOLD = 8;

    /** Bytes per triangle in {@see $packed}: nine doubles. */
    private const int TRIANGLE_BYTES = 72;

    /**
     * Materialized Triangle objects kept at most, summed over ALL trees (about 600 B each, so ~12 MB): when the
     * budget is spent (checked at the start of a query) every tree empties its cache.
     */
    private const int CACHE_LIMIT = 20000;

    private static int $cachedTotal = 0;

    /** @var array<int, \WeakReference<self>> trees that hold cached triangles */
    private static array $holders = [];

    /** @var array<int, float> min x, y, z and max x, y, z per node */
    private array $bounds = [];

    /** @var array<int, int> left child per node; a leaf stores minus its triangle count (never 0: no node has child 0) */
    private array $left = [];

    /** @var array<int, int> right child per node, or a leaf's first triangle slot */
    private array $right = [];

    /** @var string nine doubles per triangle (v0, v1, v2), in leaf order */
    private string $packed = '';

    private int $triangleTotal = 0;

    /** @var array<int, Triangle> triangle slot => materialized object */
    private array $cache = [];

    /** @var array<int, int> triangle indices in leaf order, only while building */
    private array $order = [];

    /**
     * Builds the real BVH on first use; null once built (or never deferred).
     *
     * @var (Closure(): self)|null
     */
    private ?Closure $factory = null;

    /** Root bounds as plain properties: the miss test every query starts with. An empty tree keeps inverted infinite bounds, so every query misses. */
    private float $rootMinX = INF;
    private float $rootMinY = INF;
    private float $rootMinZ = INF;
    private float $rootMaxX = -INF;
    private float $rootMaxY = -INF;
    private float $rootMaxZ = -INF;

    private function __construct() {}

    /**
     * Build a BVH from an array of triangles.
     *
     * @param Triangle[] $triangles
     */
    public static function build(array $triangles): self
    {
        $bvh = new self();
        if ($triangles === []) {
            return $bvh;
        }

        $src = '';
        $cx = $cy = $cz = $minX = $minY = $minZ = $maxX = $maxY = $maxZ = [];
        foreach ($triangles as $tri) {
            $a = $tri->v0;
            $b = $tri->v1;
            $c = $tri->v2;
            $src .= pack('d9', $a->x, $a->y, $a->z, $b->x, $b->y, $b->z, $c->x, $c->y, $c->z);
            $cx[] = $a->x + $b->x + $c->x;
            $cy[] = $a->y + $b->y + $c->y;
            $cz[] = $a->z + $b->z + $c->z;
            $minX[] = min($a->x, $b->x, $c->x);
            $minY[] = min($a->y, $b->y, $c->y);
            $minZ[] = min($a->z, $b->z, $c->z);
            $maxX[] = max($a->x, $b->x, $c->x);
            $maxY[] = max($a->y, $b->y, $c->y);
            $maxZ[] = max($a->z, $b->z, $c->z);
        }

        $bvh->finish($src, count($cx), [$cx, $cy, $cz], [$minX, $minY, $minZ, $maxX, $maxY, $maxZ]);

        return $bvh;
    }

    /**
     * Build a BVH straight from triangle-list mesh data – x, y, z per vertex and
     * three vertex indices per triangle, as in {@see \PHPolygon\Geometry\MeshData} –
     * without building a Triangle list first.
     *
     * With `$world` the vertices are moved into world space first (each vertex once,
     * through {@see Mat4::transformPoint()}, so the values are those of transforming
     * every triangle's corners). With `$dropDegenerate` a triangle whose normal
     * collapses (zero area, as {@see Triangle::isDegenerate()}) is left out; the
     * default keeps every triangle.
     *
     * @param array<float> $vertices
     * @param array<int>   $indices
     */
    public static function fromMesh(array $vertices, array $indices, ?Mat4 $world = null, bool $dropDegenerate = false): self
    {
        $bvh = new self();
        $count = intdiv(count($indices), 3);
        if ($count === 0) {
            return $bvh;
        }

        if ($world !== null) {
            $moved = [];
            for ($v = 0, $n = intdiv(count($vertices), 3) * 3; $v < $n; $v += 3) {
                $p = $world->transformPoint(new Vec3((float) $vertices[$v], (float) $vertices[$v + 1], (float) $vertices[$v + 2]));
                $moved[] = $p->x;
                $moved[] = $p->y;
                $moved[] = $p->z;
            }
            $vertices = $moved;
            unset($moved);
        }

        $src = '';
        $cx = $cy = $cz = $minX = $minY = $minZ = $maxX = $maxY = $maxZ = [];
        for ($i = 0, $end = $count * 3; $i < $end; $i += 3) {
            $a = $indices[$i] * 3;
            $b = $indices[$i + 1] * 3;
            $c = $indices[$i + 2] * 3;
            $ax = (float) $vertices[$a];
            $ay = (float) $vertices[$a + 1];
            $az = (float) $vertices[$a + 2];
            $bx = (float) $vertices[$b];
            $by = (float) $vertices[$b + 1];
            $bz = (float) $vertices[$b + 2];
            $qx = (float) $vertices[$c];
            $qy = (float) $vertices[$c + 1];
            $qz = (float) $vertices[$c + 2];
            if ($dropDegenerate) {
                // Triangle::__construct(): normal = cross(v1 - v0, v2 - v0) / length, zero from length 1e-10 down.
                $e1x = $bx - $ax;
                $e1y = $by - $ay;
                $e1z = $bz - $az;
                $e2x = $qx - $ax;
                $e2y = $qy - $ay;
                $e2z = $qz - $az;
                $nx = $e1y * $e2z - $e1z * $e2y;
                $ny = $e1z * $e2x - $e1x * $e2z;
                $nz = $e1x * $e2y - $e1y * $e2x;
                if (!(sqrt($nx * $nx + $ny * $ny + $nz * $nz) > 1e-10)) {
                    continue;
                }
            }
            $src .= pack('d9', $ax, $ay, $az, $bx, $by, $bz, $qx, $qy, $qz);
            $cx[] = $ax + $bx + $qx;
            $cy[] = $ay + $by + $qy;
            $cz[] = $az + $bz + $qz;
            $minX[] = min($ax, $bx, $qx);
            $minY[] = min($ay, $by, $qy);
            $minZ[] = min($az, $bz, $qz);
            $maxX[] = max($ax, $bx, $qx);
            $maxY[] = max($ay, $by, $qy);
            $maxZ[] = max($az, $bz, $qz);
        }
        unset($vertices);

        if ($cx !== []) {
            $bvh->finish($src, count($cx), [$cx, $cy, $cz], [$minX, $minY, $minZ, $maxX, $maxY, $maxZ]);
        }

        return $bvh;
    }

    /**
     * A BVH that is not built yet. `$min` / `$max` must enclose every triangle `$factory` will build
     * the tree from; queries that miss that box cost nothing, the first one that touches it runs
     * `$factory` (which returns the real BVH) and from then on the tree answers as if it had been
     * built up front. The bound is replaced by the exact one at that point.
     *
     * @param Closure(): self $factory
     */
    public static function deferred(Closure $factory, Vec3 $min, Vec3 $max): self
    {
        $bvh = new self();
        $bvh->factory = $factory;
        $bvh->rootMinX = $min->x;
        $bvh->rootMinY = $min->y;
        $bvh->rootMinZ = $min->z;
        $bvh->rootMaxX = $max->x;
        $bvh->rootMaxY = $max->y;
        $bvh->rootMaxZ = $max->z;

        return $bvh;
    }

    /** Has the tree been built (always true unless created by {@see deferred()} and not queried yet). */
    public function isBuilt(): bool
    {
        return $this->factory === null;
    }

    /**
     * Query all triangles whose leaf AABB overlaps the given query AABB.
     *
     * @return list<Triangle>
     */
    public function query(Vec3 $queryMin, Vec3 $queryMax): array
    {
        // Root first, before anything is copied or allocated: in a world with many
        // colliders almost every one misses a character-sized box, usually on the
        // first comparison.
        if ($queryMax->x < $this->rootMinX || $queryMin->x > $this->rootMaxX
            || $queryMax->z < $this->rootMinZ || $queryMin->z > $this->rootMaxZ
            || $queryMax->y < $this->rootMinY || $queryMin->y > $this->rootMaxY) {
            return [];
        }
        if ($this->factory !== null) {
            $this->realize();
            if ($queryMax->x < $this->rootMinX || $queryMin->x > $this->rootMaxX
                || $queryMax->z < $this->rootMinZ || $queryMin->z > $this->rootMaxZ
                || $queryMax->y < $this->rootMinY || $queryMin->y > $this->rootMaxY) {
                return [];
            }
        }

        $bounds = $this->bounds;
        $left = $this->left;
        $right = $this->right;
        if (self::$cachedTotal > self::CACHE_LIMIT) {
            foreach (self::$holders as $holder) {
                $tree = $holder->get();
                if ($tree !== null) {
                    $tree->cache = [];
                }
            }
            self::$holders = [];
            self::$cachedTotal = 0;
        }

        $minX = $queryMin->x;
        $minY = $queryMin->y;
        $minZ = $queryMin->z;
        $maxX = $queryMax->x;
        $maxY = $queryMax->y;
        $maxZ = $queryMax->z;
        $result = [];
        $stack = [0];
        while ($stack !== []) {
            $node = array_pop($stack);
            $o = $node * 6;
            if ($maxX < $bounds[$o] || $minX > $bounds[$o + 3]
                || $maxY < $bounds[$o + 1] || $minY > $bounds[$o + 4]
                || $maxZ < $bounds[$o + 2] || $minZ > $bounds[$o + 5]) {
                continue;
            }

            if ($left[$node] < 0) {
                for ($k = $right[$node], $end = $k - $left[$node]; $k < $end; $k++) {
                    $result[] = $this->cache[$k] ?? $this->triangleAt($k);
                }
                continue;
            }

            // Right first onto the stack: the left subtree is visited first.
            $stack[] = $right[$node];
            $stack[] = $left[$node];
        }

        return $result;
    }

    /**
     * Total number of triangles in this BVH (builds a deferred one).
     */
    public function triangleCount(): int
    {
        if ($this->factory !== null) {
            $this->realize();
        }

        return $this->triangleTotal;
    }

    private function triangleAt(int $slot): Triangle
    {
        /** @var array<int, float> $d */
        $d = unpack('d9', $this->packed, $slot * self::TRIANGLE_BYTES);

        self::$cachedTotal++;
        if ($this->cache === []) {
            self::$holders[spl_object_id($this)] = \WeakReference::create($this);
        }

        return $this->cache[$slot] = new Triangle(
            new Vec3($d[1], $d[2], $d[3]),
            new Vec3($d[4], $d[5], $d[6]),
            new Vec3($d[7], $d[8], $d[9]),
        );
    }

    /** Run the factory of a deferred tree and take over what it built. */
    private function realize(): void
    {
        $factory = $this->factory;
        $this->factory = null;
        if ($factory === null) {
            return;
        }
        $built = $factory();
        $this->bounds = $built->bounds;
        $this->left = $built->left;
        $this->right = $built->right;
        $this->packed = $built->packed;
        $this->triangleTotal = $built->triangleTotal;
        $this->cache = [];
        $this->rootMinX = $built->rootMinX;
        $this->rootMinY = $built->rootMinY;
        $this->rootMinZ = $built->rootMinZ;
        $this->rootMaxX = $built->rootMaxX;
        $this->rootMaxY = $built->rootMaxY;
        $this->rootMaxZ = $built->rootMaxZ;
    }

    /**
     * Build the tree over $count triangles whose corners are in $src, and store them in leaf order.
     *
     * @param array{list<float>, list<float>, list<float>} $centroids three times the centroid per triangle, per axis
     * @param array{list<float>, list<float>, list<float>, list<float>, list<float>, list<float>} $boxes triangle bounds: min x, y, z, max x, y, z
     */
    private function finish(string $src, int $count, array $centroids, array $boxes): void
    {
        $this->buildNode(range(0, $count - 1), $centroids, $boxes);

        $packed = '';
        foreach ($this->order as $index) {
            $packed .= substr($src, $index * self::TRIANGLE_BYTES, self::TRIANGLE_BYTES);
        }
        $this->packed = $packed;
        $this->triangleTotal = $count;
        $this->order = [];

        $this->rootMinX = $this->bounds[0];
        $this->rootMinY = $this->bounds[1];
        $this->rootMinZ = $this->bounds[2];
        $this->rootMaxX = $this->bounds[3];
        $this->rootMaxY = $this->bounds[4];
        $this->rootMaxZ = $this->bounds[5];
    }

    /**
     * Build the node for the triangles $ids and return its index; leaves append their triangles to
     * $this->order, so it ends up in leaf order.
     *
     * @param array<int, int> $ids
     * @param array{list<float>, list<float>, list<float>} $centroids
     * @param array{list<float>, list<float>, list<float>, list<float>, list<float>, list<float>} $boxes
     */
    private function buildNode(array $ids, array $centroids, array $boxes): int
    {
        $node = count($this->left);
        $this->left[] = -1;
        $this->right[] = 0;
        array_push($this->bounds, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
        $o = $node * 6;
        $count = count($ids);

        if ($count <= self::LEAF_THRESHOLD) {
            [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $boxes;
            $lx = $ly = $lz = PHP_FLOAT_MAX;
            $hx = $hy = $hz = -PHP_FLOAT_MAX;
            foreach ($ids as $t) {
                $lx = min($lx, $minX[$t]);
                $ly = min($ly, $minY[$t]);
                $lz = min($lz, $minZ[$t]);
                $hx = max($hx, $maxX[$t]);
                $hy = max($hy, $maxY[$t]);
                $hz = max($hz, $maxZ[$t]);
                $this->order[] = $t;
            }
            $this->bounds[$o] = $lx;
            $this->bounds[$o + 1] = $ly;
            $this->bounds[$o + 2] = $lz;
            $this->bounds[$o + 3] = $hx;
            $this->bounds[$o + 4] = $hy;
            $this->bounds[$o + 5] = $hz;
            $this->right[$node] = count($this->order) - $count;
            $this->left[$node] = -$count;
            return $node;
        }

        // Order the range along the longest centroid extent and split at the median.
        [$cx, $cy, $cz] = $centroids;
        $kx = $ky = $kz = [];
        $lx = $ly = $lz = INF;
        $hx = $hy = $hz = -INF;
        foreach ($ids as $t) {
            $x = $cx[$t];
            $y = $cy[$t];
            $z = $cz[$t];
            $kx[] = $x;
            $ky[] = $y;
            $kz[] = $z;
            $lx = min($lx, $x);
            $hx = max($hx, $x);
            $ly = min($ly, $y);
            $hy = max($hy, $y);
            $lz = min($lz, $z);
            $hz = max($hz, $z);
        }
        $ex = $hx - $lx;
        $ey = $hy - $ly;
        $ez = $hz - $lz;
        $keys = ($ex >= $ey && $ex >= $ez) ? $kx : ($ey >= $ez ? $ky : $kz);
        unset($kx, $ky, $kz);
        $ids = array_values($ids);
        array_multisort($keys, SORT_ASC, SORT_NUMERIC, $ids);
        unset($keys);

        $mid = intdiv($count, 2);
        $leftIds = array_slice($ids, 0, $mid);
        $rightIds = array_slice($ids, $mid);
        unset($ids);
        $leftChild = $this->buildNode($leftIds, $centroids, $boxes);
        unset($leftIds);
        $rightChild = $this->buildNode($rightIds, $centroids, $boxes);
        unset($rightIds);
        $this->left[$node] = $leftChild;
        $this->right[$node] = $rightChild;

        $l = $leftChild * 6;
        $r = $rightChild * 6;
        $this->bounds[$o] = min($this->bounds[$l], $this->bounds[$r]);
        $this->bounds[$o + 1] = min($this->bounds[$l + 1], $this->bounds[$r + 1]);
        $this->bounds[$o + 2] = min($this->bounds[$l + 2], $this->bounds[$r + 2]);
        $this->bounds[$o + 3] = max($this->bounds[$l + 3], $this->bounds[$r + 3]);
        $this->bounds[$o + 4] = max($this->bounds[$l + 4], $this->bounds[$r + 4]);
        $this->bounds[$o + 5] = max($this->bounds[$l + 5], $this->bounds[$r + 5]);

        return $node;
    }
}
