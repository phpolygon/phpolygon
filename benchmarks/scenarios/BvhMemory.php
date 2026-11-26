<?php

declare(strict_types=1);

/**
 * Memory and build time of a collider BVH over a mesh, per triangle.
 *
 *   php benchmarks/scenarios/BvhMemory.php [triangles=200000] [route=triangles|mesh]
 *
 * route "triangles": world-space Triangle objects, then BVH::build() (what Physics3DSystem did).
 * route "mesh":      BVH::fromMesh() with the world matrix, straight from MeshData arrays.
 * "retained" is the heap the finished BVH keeps (inputs freed), "peak" the high-water mark of the build.
 * Run each route in a fresh process.
 */

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\Triangle;

require __DIR__ . '/../../vendor/autoload.php';

ini_set('memory_limit', '-1');

$target = (int) ($argv[1] ?? 200000);
$route = $argv[2] ?? 'triangles';

// A bumpy grid: two triangles per cell.
$side = (int) ceil(sqrt($target / 2));
$vertices = [];
mt_srand(7);
for ($z = 0; $z <= $side; $z++) {
    for ($x = 0; $x <= $side; $x++) {
        array_push($vertices, $x * 0.5, sin($x * 0.3) * cos($z * 0.2) * 2.0 + mt_rand(0, 100) / 1000, $z * 0.5);
    }
}
$indices = [];
$row = $side + 1;
for ($z = 0; $z < $side; $z++) {
    for ($x = 0; $x < $side; $x++) {
        $i = $z * $row + $x;
        array_push($indices, $i, $i + $row, $i + 1, $i + 1, $i + $row, $i + $row + 1);
    }
}
$count = intdiv(count($indices), 3);
$world = new Mat4([
    0.9, 0.0, 0.1, 0.0,
    0.0, 1.0, 0.0, 0.0,
    -0.1, 0.0, 0.9, 0.0,
    10.0, 2.0, -5.0, 1.0,
]);

gc_collect_cycles();
$base = memory_get_usage();
memory_reset_peak_usage();
$t = hrtime(true);
if ($route === 'mesh') {
    $bvh = BVH::fromMesh($vertices, $indices, $world, true);
} else {
    $triangles = [];
    for ($i = 0, $n = count($indices); $i < $n; $i += 3) {
        $p = [];
        foreach ([$indices[$i], $indices[$i + 1], $indices[$i + 2]] as $v) {
            $p[] = $world->transformPoint(new Vec3($vertices[$v * 3], $vertices[$v * 3 + 1], $vertices[$v * 3 + 2]));
        }
        $tri = new Triangle($p[0], $p[1], $p[2]);
        if (!$tri->isDegenerate()) {
            $triangles[] = $tri;
        }
    }
    $bvh = BVH::build($triangles);
    unset($triangles, $tri, $p);
}
$ms = (hrtime(true) - $t) / 1e6;
gc_collect_cycles();
$retained = memory_get_usage() - $base;
$peak = memory_get_peak_usage() - $base;

// Query cost and touched memory: a character-sized box, 200 positions.
$q = hrtime(true);
$hits = 0;
for ($k = 0; $k < 200; $k++) {
    $x = 10 + ($k % 20) * 3.0;
    $z = -5 + intdiv($k, 20) * 3.0;
    $hits += count($bvh->query(new Vec3($x - 0.4, -10, $z - 0.4), new Vec3($x + 0.4, 10, $z + 0.4)));
}
$qms = (hrtime(true) - $q) / 1e6;

printf(
    "%s, %d triangles in the BVH: build %.0f ms, retained %.1f MB = %.0f B/triangle, peak %.1f MB = %.0f B/triangle; 200 queries %.1f ms (%d hits), heap after queries %.1f MB\n",
    $route,
    $bvh->triangleCount(),
    $ms,
    $retained / 1048576,
    $retained / $count,
    $peak / 1048576,
    $peak / $count,
    $qms,
    $hits,
    (memory_get_usage() - $base) / 1048576,
);
