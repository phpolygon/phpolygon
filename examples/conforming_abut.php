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
 *
 * A third table counts the open edges that stand more than 12 mm above the ground
 * (the side edges of the wedge on which the surface fades inside the neighbour)
 * without and with abut()'s $closeWedge.
 *
 * A second table shows abut()'s $minWidth: a fillet that hugs the neighbour's
 * edge and tapers leaves a thin strip OUTSIDE the neighbour; with a minimum width
 * the strip goes, with its kerb and hem, and the surface that stays starts flush.
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

// ---- thin remnants: abut(..., minWidth) --------------------------------------------------------
$edge = 6.0;
$strip = static function (float $minWidth) use ($ground, $neighbour): array {
    $set = new ConformingSurfaceSet($ground, cell: 0.5, lift: LIFT, hemWidth: 0.35, hemSink: 0.05);
    $set->add('main', RegionShape::polyline([[-8.0, 0.0], [8.0, 0.0]], 1.5))
        ->add('main', RegionShape::polygon([[6.5, 2.5], [5.5, 2.5], [2.6, 12.0], [7.0, 12.0]]))
        ->border('main', 0.3, 'kerb')
        ->abut($neighbour, 0.4, $minWidth);

    return $set->build();
};

echo "\nthin strip outside the neighbour (fillet tapering from 0.5 m to 3.4 m beyond the edge)\n";
printf("%-20s %16s %12s %12s %16s %8s\n", 'minWidth', 'strip area (m2)', 'kerb verts', 'hem verts', 'first lift (mm)', 'ms');
foreach ([0.0, 1.2] as $minWidth) {
    $t = hrtime(true);
    $parts = $strip($minWidth);
    $ms = (hrtime(true) - $t) / 1e6;
    // The strip: outside the neighbour (x < 6), beside the path, thinner than 1.2 m (z up to 4.5).
    $inStrip = static fn (float $x, float $z): bool => $z > 2.6 && $z < 4.5 && $x > 4.0 && $x < $edge;
    $area = 0.0;
    $m = $parts['main'];
    for ($t3 = 0, $n = count($m->indices); $t3 < $n; $t3 += 3) {
        $v = [];
        foreach ([0, 1, 2] as $k) {
            $i = $m->indices[$t3 + $k] * 3;
            $v[] = [$m->vertices[$i], $m->vertices[$i + 2]];
        }
        if ($inStrip(($v[0][0] + $v[1][0] + $v[2][0]) / 3.0, ($v[0][1] + $v[1][1] + $v[2][1]) / 3.0)) {
            $area += 0.5 * abs(($v[1][0] - $v[0][0]) * ($v[2][1] - $v[0][1]) - ($v[2][0] - $v[0][0]) * ($v[1][1] - $v[0][1]));
        }
    }
    $count = static function (?MeshData $mesh) use ($inStrip): int {
        $c = 0;
        for ($i = 0, $n = $mesh === null ? 0 : count($mesh->vertices); $i < $n; $i += 3) {
            $c += $inStrip($mesh->vertices[$i], $mesh->vertices[$i + 2]) ? 1 : 0;
        }

        return $c;
    };
    // Lift where the surface along the neighbour's edge (z > 2.6) begins.
    $firstZ = INF;
    $firstLift = 0.0;
    for ($i = 0, $n = count($m->vertices); $i < $n; $i += 3) {
        if (abs($m->vertices[$i] - $edge) < 1e-6 && $m->vertices[$i + 2] > 2.6 && $m->vertices[$i + 2] < $firstZ) {
            $firstZ = $m->vertices[$i + 2];
            $firstLift = $m->vertices[$i + 1] - $ground($m->vertices[$i], $m->vertices[$i + 2]);
        }
    }
    printf("%-20s %16.2f %12d %12d %16.1f %8.0f\n", $minWidth > 0.0 ? sprintf('%.1f m', $minWidth) : 'off (default)', $area, $count($parts['kerb'] ?? null), $count($parts['main.hem'] ?? null), $firstLift * 1000.0, $ms);
}

// ---- open wedge edges: abut(..., closeWedge) ----------------------------------------------------
echo "\nopen edges higher than 12 mm above the ground (all parts of the set together)\n";
printf("%-24s %12s %14s\n", 'closeWedge', 'open edges', 'highest (mm)');
foreach ([false, true] as $close) {
    $set = new ConformingSurfaceSet($ground, cell: 0.5, lift: LIFT, hemWidth: 0.35, hemSink: 0.05);
    $set->add('main', RegionShape::polyline([[-12.0, 0.0], [8.0, 0.0]], 1.5))->border('main', 0.3, 'kerb')
        ->abut($neighbour, LAP, 0.0, $close);
    $merged = MeshData::merge(...array_values($set->build()));
    $height = [];
    $key = static fn (float $x, float $z): string => sprintf('%.5f:%.5f', round($x, 5) + 0.0, round($z, 5) + 0.0);
    for ($i = 0, $n = count($merged->vertices); $i < $n; $i += 3) {
        $height[$key($merged->vertices[$i], $merged->vertices[$i + 2])] = $merged->vertices[$i + 1] - $ground($merged->vertices[$i], $merged->vertices[$i + 2]);
    }
    $count = [];
    for ($t = 0, $n = count($merged->indices); $t < $n; $t += 3) {
        foreach ([[0, 1], [1, 2], [2, 0]] as [$a, $b]) {
            $ia = $merged->indices[$t + $a] * 3;
            $ib = $merged->indices[$t + $b] * 3;
            $ka = $key($merged->vertices[$ia], $merged->vertices[$ia + 2]);
            $kb = $key($merged->vertices[$ib], $merged->vertices[$ib + 2]);
            $e = $ka < $kb ? "{$ka}|{$kb}" : "{$kb}|{$ka}";
            $count[$e] = ($count[$e] ?? 0) + 1;
        }
    }
    $open = 0;
    $max = 0.0;
    foreach ($count as $e => $c) {
        if ($c !== 1) {
            continue;
        }
        $h = max(...array_map(static fn (string $k): float => $height[$k] ?? 0.0, explode('|', (string) $e)));
        if ($h > 0.012) {
            $open++;
        }
        $max = max($max, $h);
    }
    printf("%-24s %12d %14.1f\n", $close ? 'true' : 'false (default)', $open, $max * 1000.0);
}
