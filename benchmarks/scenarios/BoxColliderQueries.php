<?php

declare(strict_types=1);

/**
 * Costs around the box-collider index: moving turned boxes, and Raycaster3D::segmentBlocked() over a big pool.
 *
 *   php benchmarks/scenarios/BoxColliderQueries.php [boxes=8500] [turned=0.08]
 *
 * Boxes sit on a grid (3 m pitch) with a Physics3DSystem-built index, `turned` of them with a rotation.
 * "move" reassigns the world matrix of every turned box ten times and times the index sync; "segment" times
 * 2,000 camera-length segments (3-9 m) through the grid, and prints how many were blocked, so two runs can be
 * compared. Run it in a fresh process.
 */

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Raycaster3D;
use PHPolygon\System\Physics3DSystem;

require __DIR__ . '/../../vendor/autoload.php';

ini_set('memory_limit', '-1');

$n = (int) ($argv[1] ?? 8500);
$turnedShare = (float) ($argv[2] ?? 0.08);

$world = new World();
$physics = new Physics3DSystem(groundPlaneY: -1000.0);
$world->addSystem($physics);
$side = (int) ceil(sqrt($n));
$turned = [];
mt_srand(5);
for ($i = 0; $i < $n; $i++) {
    $isTurned = mt_rand(0, 9999) < $turnedShare * 10000;
    $e = $world->createEntity();
    $e->attach(new Transform3D(
        new Vec3(($i % $side) * 3.0, 1.0, intdiv($i, $side) * 3.0),
        $isTurned ? Quaternion::fromEuler(0.0, 0.5, 0.2) : Quaternion::identity(),
        new Vec3(1, 1, 1),
    ));
    $e->attach(new BoxCollider3D(new Vec3(mt_rand(10, 25) / 10, 2.0, mt_rand(10, 25) / 10)));
    if ($isTurned) {
        $turned[] = $e->id;
    }
}
$world->update(1 / 60);   // builds the index and the caches

$total = 0.0;
$moves = 0;
$index = (new ReflectionProperty($physics, 'colliders'))->getValue($physics);
for ($round = 1; $round <= 10; $round++) {
    foreach ($turned as $id) {
        $t = $world->getComponent($id, Transform3D::class);
        $t->position = new Vec3($t->position->x + 0.01, $t->position->y, $t->position->z);
        $t->worldMatrix = $t->getLocalMatrix();
    }
    $s = hrtime(true);
    $index->sync($world);
    $total += (hrtime(true) - $s) / 1e6;
    $moves += count($turned);
}
printf("move:    %d turned boxes moved %d times: sync %.1f ms total, %.1f us per moved box\n", count($turned), $moves / max(1, count($turned)), $total, $total * 1000 / max(1, $moves));

$rc = new Raycaster3D();
$extent = $side * 3.0;
mt_srand(9);
$segments = [];
for ($k = 0; $k < 2000; $k++) {
    $from = new Vec3(mt_rand(0, (int) $extent * 10) / 10, mt_rand(5, 30) / 10, mt_rand(0, (int) $extent * 10) / 10);
    $len = mt_rand(30, 90) / 10;
    $angle = mt_rand(0, 6283) / 1000;
    $segments[] = [$from, new Vec3($from->x + cos($angle) * $len, $from->y + mt_rand(-10, 10) / 10, $from->z + sin($angle) * $len)];
}
foreach ($segments as [$a, $b]) {   // warm: lazily built trees of turned boxes
    $rc->segmentBlocked($world, $a, $b);
}
$blocked = 0;
$s = hrtime(true);
foreach ($segments as [$a, $b]) {
    $blocked += $rc->segmentBlocked($world, $a, $b) ? 1 : 0;
}
$ms = (hrtime(true) - $s) / 1e6;
printf("segment: %d boxes: %.4f ms per segmentBlocked (%d of %d blocked)\n", $n, $ms / count($segments), $blocked, count($segments));
