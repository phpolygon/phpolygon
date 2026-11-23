<?php

declare(strict_types=1);

/**
 * ConformingSurfaceSet::abut() against the old feathered cut, as numbers (no
 * renderer needed): a kerbed path runs into a neighbour surface (an imported
 * road, a quay) over sloping ground.
 *
 *   php examples/conforming_abut.php
 *
 * Prints per variant: the height of the surface above the ground where it ends
 * (the open end edge: 25 mm = a visible slab end, 0 = flush), kerb and hem
 * vertices inside the neighbour, bare ground left between the end of the surface
 * and the neighbour (m), build time. The ground function here IS the neighbour's
 * visible ground too (continuous across the edge), as abut() expects.
 */

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\RegionShape;

require __DIR__ . '/../vendor/autoload.php';

const LIFT = 0.025;
const LAP = 0.4;

$ground = static fn (float $x, float $z): float => 0.04 * $x + 0.02 * $z;
$neighbour = RegionShape::rect(10.0, 0.0, 8.0, 20.0); // x 6..14

/** @return array<string, MeshData> */
$build = static function (string $variant) use ($ground, $neighbour): array {
    $set = new ConformingSurfaceSet($ground, cell: 0.5, lift: LIFT, hemWidth: 0.35, hemSink: 0.05);
    $set->add('main', RegionShape::polyline([[-12.0, 0.0], [8.0, 0.0]], 1.5))->border('main', 0.3, 'kerb');
    match ($variant) {
        'feathered cut (old, FEATHER 0.15)' => $set->cut(RegionShape::field(5.0, -10.0, 14.0, 10.0, static fn (float $x, float $z): float => $neighbour->distance($x, $z) - 0.15)),
        'cut inside the edge (old, FEATHER -0.15)' => $set->cut(RegionShape::field(6.0, -10.0, 14.0, 10.0, static fn (float $x, float $z): float => $neighbour->distance($x, $z) + 0.15)),
        default => $set->abut($neighbour, LAP),
    };

    return $set->build();
};

printf("%-44s %12s %9s %9s %10s %8s\n", 'variant', 'end height', 'kerb in', 'hem in', 'bare (m)', 'ms');
foreach (['feathered cut (old, FEATHER 0.15)', 'cut inside the edge (old, FEATHER -0.15)', 'abut(lap 0.4)'] as $variant) {
    $t = hrtime(true);
    $parts = $build($variant);
    $ms = (hrtime(true) - $t) / 1e6;
    $inside = static function (MeshData $m) use ($neighbour): int {
        $n = 0;
        for ($i = 0, $c = count($m->vertices); $i < $c; $i += 3) {
            $n += $neighbour->distance($m->vertices[$i], $m->vertices[$i + 2]) < -0.05 ? 1 : 0;
        }

        return $n;
    };
    // Bare ground: the gap between the surface's easternmost vertex on the centre line and the neighbour edge (x = 6).
    $end = -INF;
    foreach ($parts['main']->vertices as $i => $v) {
        if ($i % 3 === 0 && abs($parts['main']->vertices[$i + 2]) < 0.3) {
            $end = max($end, $v);
        }
    }
    $endLift = 0.0;
    for ($i = 0, $c = count($parts['main']->vertices); $i < $c; $i += 3) {
        [$x, $y, $z] = [$parts['main']->vertices[$i], $parts['main']->vertices[$i + 1], $parts['main']->vertices[$i + 2]];
        if (abs($x - $end) < 1e-6 && abs($z) < 0.3) {
            $endLift = max($endLift, $y - $ground($x, $z));
        }
    }
    printf(
        "%-44s %9.1f mm %9d %9d %10.2f %8.0f\n",
        $variant,
        $endLift * 1000.0,
        $inside($parts['kerb'] ?? new MeshData([], [], [], [])),
        $inside($parts['main.hem'] ?? new MeshData([], [], [], [])),
        max(0.0, 6.0 - $end),
        $ms,
    );
}
