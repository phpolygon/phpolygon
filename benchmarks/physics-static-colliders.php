<?php

declare(strict_types=1);

/**
 * Synthetic Physics3DSystem benchmark: a big static box world, one walking and one standing
 * character, and a stage-style flip of many colliders between trigger and solid.
 *
 *   php benchmarks/physics-static-colliders.php [--boxes=8500] [--meshes=0] [--flip=1500] [--ticks=300] [--seed=7]
 *
 * Prints, per phase, the mean physics.collect and physics.tick time per tick (PerfProfiler
 * sections, so the numbers are what a game's profile shows) and the worst single tick.
 * Headless, no GPU.
 */

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\System\Physics3DSystem;
use PHPolygon\System\Transform3DSystem;

require __DIR__ . '/../vendor/autoload.php';

putenv('PHPOLYGON_PROFILE=1');
PerfProfiler::reset();

$options = ['boxes' => 8500, 'meshes' => 0, 'flip' => 1500, 'ticks' => 300, 'seed' => 7];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(\w+)=(\d+)$/', $arg, $m) === 1 && isset($options[$m[1]])) {
        $options[$m[1]] = (int) $m[2];
    }
}
mt_srand($options['seed']);

$world = new World();
$transforms = new Transform3DSystem();
$physics = new Physics3DSystem(groundPlaneY: -1000.0);

// A wide floor slab (the one huge box every world has) plus the scatter.
$floor = $world->createEntity();
$floor->attach(new Transform3D(new Vec3(0.0, -0.5, 0.0)));
$floor->attach(new BoxCollider3D(size: new Vec3(900.0, 1.0, 900.0)));

/** @var list<BoxCollider3D> $flippable */
$flippable = [];
$extent = 600.0;
for ($i = 0; $i < $options['boxes']; $i++) {
    $size = new Vec3(0.5 + mt_rand(0, 550) / 100.0, 0.5 + mt_rand(0, 300) / 100.0, 0.5 + mt_rand(0, 550) / 100.0);
    $position = new Vec3((mt_rand(0, 10000) / 10000.0 - 0.5) * $extent, $size->y / 2.0, (mt_rand(0, 10000) / 10000.0 - 0.5) * $extent);
    // Keep the start and the walk line (x = -40..40, z = 0) free so the character is not boxed in.
    if (abs($position->z) < 3.0 && abs($position->x) < 60.0) {
        $position = new Vec3($position->x, $position->y, $position->z + 8.0);
    }
    $rotation = mt_rand(0, 99) < 8
        ? Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), mt_rand(5, 300) / 100.0)
        : Quaternion::identity();
    $entity = $world->createEntity();
    $entity->attach(new Transform3D($position, $rotation));
    $collider = new BoxCollider3D(size: $size, isTrigger: $i < $options['flip']);
    $entity->attach($collider);
    if ($i < $options['flip']) {
        $flippable[] = $collider;
    }
}

if ($options['meshes'] > 0) {
    MeshRegistry::register('bench_physics_box', BoxMesh::generate(2.0, 2.0, 2.0));
    for ($i = 0; $i < $options['meshes']; $i++) {
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(new Vec3((mt_rand(0, 10000) / 10000.0 - 0.5) * $extent, 1.0, (mt_rand(0, 10000) / 10000.0 - 0.5) * $extent + 100.0)));
        $entity->attach(new MeshCollider3D('bench_physics_box'));
    }
}

$walker = $world->createEntity();
$walkerTransform = new Transform3D(new Vec3(-40.0, 0.9, 0.0));
$walker->attach($walkerTransform);
$walker->attach(new CharacterController3D(height: 1.8, radius: 0.4));
$stander = $world->createEntity();
$stander->attach(new Transform3D(new Vec3(30.0, 0.9, 20.0)));
$stander->attach(new CharacterController3D(height: 1.8, radius: 0.4));

$dt = 1.0 / 60.0;
$tick = static function () use ($world, $transforms, $physics, $dt): float {
    $transforms->update($world, $dt);
    $start = hrtime(true);
    $physics->update($world, $dt);

    return (hrtime(true) - $start) / 1e6;
};

$phase = static function (string $label, int $ticks, callable $before) use ($tick): void {
    PerfProfiler::reset();
    putenv('PHPOLYGON_PROFILE=1');
    $worst = 0.0;
    $total = 0.0;
    for ($i = 0; $i < $ticks; $i++) {
        $before($i);
        $ms = $tick();
        $total += $ms;
        $worst = max($worst, $ms);
    }
    $sections = PerfProfiler::snapshot();
    $collect = ($sections['physics.collect']['avgNs'] ?? 0.0) / 1e6;
    $resolve = ($sections['physics.resolve']['avgNs'] ?? 0.0) / 1e6;
    printf(
        "%-34s tick %7.3f ms  collect %7.3f ms  resolve %7.3f ms  worst tick %8.3f ms  (%d ticks)\n",
        $label,
        $total / max(1, $ticks),
        $collect,
        $resolve,
        $worst,
        $ticks,
    );
};

printf("boxes %d (+1 floor), meshes %d, flip set %d, php %s\n", $options['boxes'], $options['meshes'], count($flippable), PHP_VERSION);

// Cold first tick: every cache is built here.
$phase('cold first tick', 1, static function (): void {});

$phase('settle', 30, static function (): void {});

// The walker moves 4 m/s along +x by assigning its position, as a game's input system does.
$walkTicks = $options['ticks'];
$phase('walking character', $walkTicks, static function (int $i) use ($walkerTransform): void {
    $p = $walkerTransform->position;
    $x = $p->x + 4.0 / 60.0;
    $walkerTransform->position = new Vec3($x > 40.0 ? -40.0 : $x, $p->y, $p->z);
});

$phase('standing characters', $walkTicks, static function (): void {});

// Stage change: every flippable collider turns solid in one frame.
$phase('flip trigger -> solid (1 tick)', 1, static function () use ($flippable): void {
    foreach ($flippable as $collider) {
        $collider->isTrigger = false;
    }
});
$phase('after flip', 60, static function (): void {});
$phase('flip solid -> trigger (1 tick)', 1, static function () use ($flippable): void {
    foreach ($flippable as $collider) {
        $collider->isTrigger = true;
    }
});
$phase('flip trigger -> solid again (1 tick)', 1, static function () use ($flippable): void {
    foreach ($flippable as $collider) {
        $collider->isTrigger = false;
    }
});

// One moving platform's worth of changes: 50 colliders get a new position.
$movers = array_slice($flippable, 0, 50);
$moverTransforms = [];
foreach ($world->query(BoxCollider3D::class, Transform3D::class) as $entity) {
    if (in_array($entity->get(BoxCollider3D::class), $movers, true)) {
        $moverTransforms[] = $entity->get(Transform3D::class);
    }
}
$phase('50 colliders moving every tick', 60, static function (int $i) use ($moverTransforms): void {
    foreach ($moverTransforms as $transform) {
        $p = $transform->position;
        $transform->position = new Vec3($p->x + 0.01, $p->y, $p->z);
    }
});
