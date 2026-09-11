<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Vec3;

/**
 * Binary bounding volume hierarchy for fast triangle queries.
 *
 * Built top-down with a median split along the longest extent of the triangle
 * centroids; leaves hold at most {@see LEAF_THRESHOLD} triangles. The tree is
 * stored flat – node bounds, child indices and leaf ranges in plain arrays, the
 * triangles in leaf order – so building allocates no per-node objects and a
 * query walks ints and floats.
 *
 * Each node's range is ordered once with array_multisort() over float keys (C,
 * no PHP callback), and a node's bounds are the union of its children's. The
 * previous object tree sorted with usort() and a callback that allocated two
 * Vec3 per comparison, which made the collider BVHs of large imported meshes
 * the dominant cost of loading a world.
 */
class BVH
{
    private const int LEAF_THRESHOLD = 8;

    /** @var array<int, float> min x, y, z and max x, y, z per node */
    private array $bounds = [];

    /** @var array<int, int> left child per node; -1 marks a leaf */
    private array $left = [];

    /** @var array<int, int> right child per node, or a leaf's first slot in $triangles */
    private array $right = [];

    /** @var array<int, int> triangles per leaf; 0 for internal nodes */
    private array $counts = [];

    /** @var list<Triangle> in leaf order */
    private array $triangles = [];

    /** @var array<int, int> triangle indices, reordered while building */
    private array $order = [];

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
        $triangles = array_values($triangles);
        $bvh = new self();
        if ($triangles === []) {
            return $bvh;
        }

        $cx = $cy = $cz = $minX = $minY = $minZ = $maxX = $maxY = $maxZ = [];
        foreach ($triangles as $tri) {
            $a = $tri->v0;
            $b = $tri->v1;
            $c = $tri->v2;
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

        $bvh->buildTree(count($triangles), [$cx, $cy, $cz], [$minX, $minY, $minZ, $maxX, $maxY, $maxZ]);
        foreach ($bvh->order as $index) {
            $bvh->triangles[] = $triangles[$index];
        }
        $bvh->order = [];

        return $bvh;
    }

    /**
     * Build a BVH straight from triangle-list mesh data – x, y, z per vertex and
     * three vertex indices per triangle, as in {@see \PHPolygon\Geometry\MeshData} –
     * without building a Triangle list first. The Triangle objects are created
     * once, already in leaf order.
     *
     * @param array<int, float> $vertices
     * @param array<int, int>   $indices
     */
    public static function fromMesh(array $vertices, array $indices): self
    {
        $bvh = new self();
        $count = intdiv(count($indices), 3);
        if ($count === 0) {
            return $bvh;
        }

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

        $bvh->buildTree($count, [$cx, $cy, $cz], [$minX, $minY, $minZ, $maxX, $maxY, $maxZ]);
        foreach ($bvh->order as $index) {
            $i = $index * 3;
            $a = $indices[$i] * 3;
            $b = $indices[$i + 1] * 3;
            $c = $indices[$i + 2] * 3;
            $bvh->triangles[] = new Triangle(
                new Vec3((float) $vertices[$a], (float) $vertices[$a + 1], (float) $vertices[$a + 2]),
                new Vec3((float) $vertices[$b], (float) $vertices[$b + 1], (float) $vertices[$b + 2]),
                new Vec3((float) $vertices[$c], (float) $vertices[$c + 1], (float) $vertices[$c + 2]),
            );
        }
        $bvh->order = [];

        return $bvh;
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

        $bounds = $this->bounds;

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

            if ($this->left[$node] < 0) {
                for ($k = $this->right[$node], $end = $k + $this->counts[$node]; $k < $end; $k++) {
                    $result[] = $this->triangles[$k];
                }
                continue;
            }

            // Right first onto the stack: the left subtree is visited first.
            $stack[] = $this->right[$node];
            $stack[] = $this->left[$node];
        }

        return $result;
    }

    /**
     * Total number of triangles in this BVH.
     */
    public function triangleCount(): int
    {
        return count($this->triangles);
    }

    /**
     * @param array{list<float>, list<float>, list<float>} $centroids three times the centroid per triangle, per axis
     * @param array{list<float>, list<float>, list<float>, list<float>, list<float>, list<float>} $boxes triangle bounds: min x, y, z, max x, y, z
     */
    private function buildTree(int $count, array $centroids, array $boxes): void
    {
        $this->order = range(0, $count - 1);
        $this->buildNode(0, $count, $centroids, $boxes);
        $this->rootMinX = $this->bounds[0];
        $this->rootMinY = $this->bounds[1];
        $this->rootMinZ = $this->bounds[2];
        $this->rootMaxX = $this->bounds[3];
        $this->rootMaxY = $this->bounds[4];
        $this->rootMaxZ = $this->bounds[5];
    }

    /**
     * Build the node for $this->order[$start, $end) and return its index.
     *
     * @param array{list<float>, list<float>, list<float>} $centroids
     * @param array{list<float>, list<float>, list<float>, list<float>, list<float>, list<float>} $boxes
     */
    private function buildNode(int $start, int $end, array $centroids, array $boxes): int
    {
        $node = count($this->left);
        $this->left[] = -1;
        $this->right[] = 0;
        $this->counts[] = 0;
        array_push($this->bounds, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
        $o = $node * 6;
        $count = $end - $start;

        if ($count <= self::LEAF_THRESHOLD) {
            [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $boxes;
            $lx = $ly = $lz = PHP_FLOAT_MAX;
            $hx = $hy = $hz = -PHP_FLOAT_MAX;
            for ($k = $start; $k < $end; $k++) {
                $t = $this->order[$k];
                $lx = min($lx, $minX[$t]);
                $ly = min($ly, $minY[$t]);
                $lz = min($lz, $minZ[$t]);
                $hx = max($hx, $maxX[$t]);
                $hy = max($hy, $maxY[$t]);
                $hz = max($hz, $maxZ[$t]);
            }
            $this->bounds[$o] = $lx;
            $this->bounds[$o + 1] = $ly;
            $this->bounds[$o + 2] = $lz;
            $this->bounds[$o + 3] = $hx;
            $this->bounds[$o + 4] = $hy;
            $this->bounds[$o + 5] = $hz;
            $this->right[$node] = $start;
            $this->counts[$node] = $count;
            return $node;
        }

        // Order the range along the longest centroid extent and split at the median.
        $range = array_slice($this->order, $start, $count);
        [$cx, $cy, $cz] = $centroids;
        $kx = $ky = $kz = [];
        foreach ($range as $t) {
            $kx[] = $cx[$t];
            $ky[] = $cy[$t];
            $kz[] = $cz[$t];
        }
        if ($kx === []) {
            return $node;   // unreachable: an inner node holds more than LEAF_THRESHOLD triangles
        }
        $ex = max($kx) - min($kx);
        $ey = max($ky) - min($ky);
        $ez = max($kz) - min($kz);
        $keys = ($ex >= $ey && $ex >= $ez) ? $kx : ($ey >= $ez ? $ky : $kz);
        unset($kx, $ky, $kz);
        array_multisort($keys, SORT_ASC, SORT_NUMERIC, $range);
        array_splice($this->order, $start, $count, $range);
        unset($keys, $range);

        $mid = $start + intdiv($count, 2);
        $leftChild = $this->buildNode($start, $mid, $centroids, $boxes);
        $rightChild = $this->buildNode($mid, $end, $centroids, $boxes);
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
