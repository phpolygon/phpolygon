<?php

declare(strict_types=1);

/**
 * Walks a camera through grass over a worst-case mask and reports GrassSystem::render()
 * per tick, in real time: what a player would feel of the cell generation. The mask is
 * deliberately crowded (many long paths, polygons of hundreds of edges), the ground
 * walks a triangle list per point and two fields lie on top of each other.
 *
 *   php benchmarks/grass-walk.php [--speed=5] [--fps=60] [--runs=3] [--plain]
 *
 * `--plain` reads mask and ground on every candidate without tiles and raster, which
 * is what a game gets that hands in its slow callables directly.
 */

use PHPolygon\Component\GrassField;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\GrassQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\GrassSystem;
use PHPolygon\Vegetation\GrassGroundRaster;
use PHPolygon\Vegetation\GrassMask;

require __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '-1');
$options = getopt('', ['speed::', 'fps::', 'runs::', 'plain']);
$speed = (float) ($options['speed'] ?? 5.0);
$fps = (float) ($options['fps'] ?? 60.0);
$runs = (int) ($options['runs'] ?? 3);
$plain = isset($options['plain']);

/** Paths and polygons all over the field, no tile free of them. */
function crowdedMask(): GrassMask
{
    $mask = new GrassMask();
    mt_srand(11);
    for ($i = 0; $i < 60; $i++) {
        $x = mt_rand(-140, 140);
        $z = mt_rand(-140, 140);
        $a = mt_rand(0, 628) / 100.0;
        $points = [];
        for ($j = 0; $j < 40; $j++) {
            $points[] = [$x + $j * 4.0 * cos($a), $z + $j * 4.0 * sin($a) + 3.0 * sin($j * 0.7)];
        }
        $mask->excludePath($points, 0.9, 0.7);
    }
    for ($i = 0; $i < 12; $i++) {
        $cx = mt_rand(-130, 130);
        $cz = mt_rand(-130, 130);
        $ring = [];
        for ($j = 0; $j < 200; $j++) {
            $a = $j / 200.0 * 2.0 * M_PI;
            $r = 14.0 + 7.0 * sin($a * 7.0) + 3.0 * cos($a * 13.0);
            $ring[] = [$cx + cos($a) * $r, $cz + sin($a) * $r];
        }
        $mask->excludePolygon($ring, 0.8);
    }
    return $mask;
}

/** A ground that costs about what a walk over real geometry does per point. */
function slowGround(float $x, float $z): float
{
    $y = 0.0;
    for ($i = 0; $i < 300; $i++) {
        $y = max($y, sin($x * 0.01 + $i) * 0.001);
    }
    return $y + 0.2 * sin($x * 0.05) * cos($z * 0.04);
}

$percentile = static function (array $values, float $q): float {
    sort($values);
    return $values[(int) min(count($values) - 1, floor(count($values) * $q))];
};

for ($run = 1; $run <= $runs; $run++) {
    MeshRegistry::clear();
    MaterialRegistry::clear();
    $exclusions = crowdedMask();
    $ground = 'slowGround';
    if (!$plain) {
        $ground = GrassGroundRaster::fromHeight('slowGround')->heightAt(...);
    }
    $world = new World();
    foreach ([1, 2] as $seed) {
        $mask = $plain ? (new GrassMask())->multiply($exclusions->factorExhaustive(...)) : (new GrassMask())->multiplyMask($exclusions);
        $world->createEntity()->attach(new GrassField(-150.0, -150.0, 150.0, 150.0, $ground, $mask, seed: $seed));
    }
    $list = new RenderCommandList();
    $system = new GrassSystem($list, GrassQuality::Medium);
    $projection = Mat4::perspective(deg2rad(70.0), 16 / 9, 0.1, 2000.0);

    $t = hrtime(true);
    $system->prewarm($world, 0.0, 130.0);
    $prewarmMs = (hrtime(true) - $t) / 1e6;

    $ticks = [];
    $generate = [];
    $nearest = INF;
    $over = 0;
    for ($z = 130.0; $z > -130.0; $z -= $speed / $fps) {
        $list->clear();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.7, $z), new Vec3(0.0, 1.4, $z - 1.0), new Vec3(0.0, 1.0, 0.0)),
            $projection,
        ));
        $gc = gc_status()['runs'];
        $t = hrtime(true);
        $system->render($world);
        $ms = (hrtime(true) - $t) / 1e6;
        if (gc_status()['runs'] !== $gc) {
            continue; // the collector walks the whole heap: not what is measured here
        }
        $ticks[] = $ms;
        $generate[] = $system->lastGenerateMs;
        $nearest = min($nearest, $system->lastMissingDistance);
        $over += $ms > 8.0 ? 1 : 0;
    }
    printf(
        "run %d: prewarm %.0f ms | tick p50 %.2f p99 %.2f max %.2f ms, over 8 ms: %d of %d | generate max %.2f ms | nearest missing cell %.1f m\n",
        $run, $prewarmMs, $percentile($ticks, 0.5), $percentile($ticks, 0.99), max($ticks), $over, count($ticks), max($generate), $nearest,
    );
}
