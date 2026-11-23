<?php

declare(strict_types=1);

/**
 * Cost of building one cumulative family of ConformingSurfaceSets (stage k holds
 * all paving of stages <= k), the way a staged-reveal district uses it. Prints
 * wall time, ground queries and a checksum of every part, so a faster mode can
 * be checked for the same output.
 *
 * Usage: php -d memory_limit=-1 benchmarks/conforming-stages.php [stages] [runs] [mode]
 *   mode: plain  every set on its own (the behaviour without a shared cache)
 *         shared one GroundMemo for the whole family
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\GroundMemo;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\RegionShape;

$stages = (int) ($argv[1] ?? 5);
$runs = (int) ($argv[2] ?? 3);
$mode = $argv[3] ?? 'plain';

/**
 * Deterministic pseudo-random network: one group of polylines and discs per stage.
 *
 * @return array<int, list<array{string, RegionShape}>>
 */
function network(int $stages): array
{
    mt_srand(7);
    $groups = [];
    for ($s = 1; $s <= $stages; $s++) {
        $shapes = [];
        for ($i = 0; $i < 3; $i++) {
            $x = mt_rand(-120, 120);
            $z = mt_rand(-120, 120);
            $pts = [[(float) $x, (float) $z]];
            for ($j = 0; $j < 4; $j++) {
                $x += mt_rand(-30, 30);
                $z += mt_rand(-30, 30);
                $pts[] = [(float) $x, (float) $z];
            }
            $shapes[] = ['main', RegionShape::polyline($pts, mt_rand(15, 30) / 10.0)];
        }
        for ($i = 0; $i < 2; $i++) {
            $shapes[] = ['side', RegionShape::circle(mt_rand(-120, 120), mt_rand(-120, 120), mt_rand(30, 80) / 10.0)];
        }
        $groups[$s] = $shapes;
    }

    return $groups;
}

$groups = network($stages);
$queries = 0;
$ground = static function (float $x, float $z) use (&$queries): float {
    $queries++;

    return sin($x * 0.05) * cos($z * 0.04) * 3.0 + sin($x * 0.31 + $z * 0.27) * 0.2;
};

for ($r = 0; $r < $runs; $r++) {
    $queries = 0;
    $t = hrtime(true);
    $parts = [];
    $acc = [];
    $gridNs = 0;
    $family = $mode === 'shared' ? new GroundMemo($ground) : $ground;
    foreach ($groups as $stage => $shapes) {
        foreach ($shapes as $sh) {
            $acc[] = $sh;
        }
        $set = new ConformingSurfaceSet($family, 0.5, 0.025, 0.35, 0.05);
        foreach ($acc as [$surface, $shape]) {
            $set->add($surface, $shape);
        }
        $set->border('main', 0.3, 'kerb');
        if ($stage === count($groups)) {
            $set->inlay('marker', RegionShape::circle(0.0, 0.0, 2.0));
        }
        $g = hrtime(true);
        $set->grid();
        $gridNs += hrtime(true) - $g;
        $parts[$stage] = $set->build();
    }
    $sec = (hrtime(true) - $t) / 1e9;
    $verts = 0;
    $sum = 0.0;
    foreach ($parts as $stageParts) {
        /** @var MeshData $m */
        foreach ($stageParts as $m) {
            $verts += intdiv(count($m->vertices), 3);
            $sum += array_sum($m->vertices) + array_sum($m->indices) * 1e-3;
        }
    }
    printf("%s run %d: %.2f s (grid %.2f s), %d ground queries, %d vertices, checksum %.6f\n", $mode, $r, $sec, $gridNs / 1e9, $queries, $verts, $sum);
}
