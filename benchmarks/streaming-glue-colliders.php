<?php

declare(strict_types=1);

// Section "colliders" of benchmarks/streaming-glue.php (required by it, helpers come from there).

use PHPolygon\Physics\CellColliderGrid;

echo "\n== CellColliderGrid ==\n";

/** @return list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> */
$boxesFor = static function (int $seed, int $n, float $ox, float $oz, float $span, int $idBase): array {
    mt_srand($seed);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $x = $ox + mt_rand(0, (int) ($span * 8)) / 8;
        $z = $oz + mt_rand(0, (int) ($span * 8)) / 8;
        $out[] = [$idBase + $i, $x, 0.0, $z, $x + mt_rand(8, 160) / 8, mt_rand(16, 160) / 8, $z + mt_rand(8, 160) / 8];
    }

    return $out;
};

foreach ([500, 2000, 5000] as $n) {
    $boxes = $boxesFor(1, $n, 0.0, 0.0, 256.0, 0);
    $packed = CellColliderGrid::packBoxes($boxes);
    printf("-- one cell, %d boxes over 256 x 256 --\n", $n);
    printf("  packBoxes (worker-side)   %s\n", summarize(sample(fn () => CellColliderGrid::packBoxes($boxes), 7)));
    $g = new CellColliderGrid(8.0);
    printf("  add()                     %s\n", summarize(sample(fn () => $g->add('c', $packed), 15)));
    printf("  prepare() (index build)   %s\n", summarize(sample(function () use ($g, $packed) {
        $g->add('c', $packed);
        $g->prepare('c');
    }, 9)));
    $g->setActive(['c']);
    printf("  query 1 x 2 x 1 m         %s\n", summarize(sample(fn () => $g->query(100.0, 0.0, 100.0, 101.0, 2.0, 101.0), 500)));
    printf("  query 16 x 4 x 16 m       %s\n", summarize(sample(fn () => $g->query(100.0, 0.0, 100.0, 116.0, 4.0, 116.0), 500)));
}

// 81 loaded cells x 2000 boxes = 162,000 boxes; 9 of them active.
$g = new CellColliderGrid(8.0);
$all = [];
for ($cz = 0; $cz < 9; $cz++) {
    for ($cx = 0; $cx < 9; $cx++) {
        $boxes = $boxesFor($cz * 9 + $cx, 2000, $cx * 256.0, $cz * 256.0, 250.0, ($cz * 9 + $cx) * 2000);
        $g->add("{$cx}:{$cz}", CellColliderGrid::packBoxes($boxes));
        $g->prepare("{$cx}:{$cz}");
        if ($cx >= 3 && $cx <= 5 && $cz >= 3 && $cz <= 5) {
            $all = array_merge($all, $boxes);
        }
    }
}
$active = [];
for ($cz = 3; $cz <= 5; $cz++) {
    for ($cx = 3; $cx <= 5; $cx++) {
        $active[] = "{$cx}:{$cz}";
    }
}
$g->setActive($active);
$qx = 4 * 256.0 + 100.0;
$qz = 4 * 256.0 + 100.0;
printf("-- 81 loaded cells x 2000 boxes (%d boxes), 9 active (%d boxes) --\n", $g->colliderCount(), $g->activeColliderCount());
printf("  grid query 1 x 2 x 1 m, 9 active cells        %s\n", summarize(sample(fn () => $g->query($qx, 0.0, $qz, $qx + 1.0, 2.0, $qz + 1.0), 500)));
$linear = static function () use ($all, $qx, $qz): int {
    $n = 0;
    foreach ($all as $b) {
        if ($b[1] < $qx + 1.0 && $b[4] > $qx && $b[2] < 2.0 && $b[5] > 0.0 && $b[3] < $qz + 1.0 && $b[6] > $qz) {
            $n++;
        }
    }

    return $n;
};
printf("  linear scan of the same 18000 boxes (PHP list) %s\n", summarize(sample($linear, 50)));
$t = hrtime(true);
$activations = 0;
for ($i = 0; $i < 200; $i++) {
    $g->setActive($i % 2 === 0 ? $active : array_slice($active, 3));
    $activations++;
}
printf("  setActive() flip                               %.4f ms per call\n", (hrtime(true) - $t) / 1e6 / $activations);
