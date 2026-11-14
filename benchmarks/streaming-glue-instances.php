<?php

declare(strict_types=1);

// Section "instances" of benchmarks/streaming-glue.php (required by it, helpers come from there).

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\BatchBounds;
use PHPolygon\Rendering\CellInstanceBuffers;

echo "\n== CellInstanceBuffers ==\n";
MeshRegistry::register('bench_box', BoxMesh::generate(1.0, 1.0, 1.0));

$row = static function (int $n): string {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = pack('g16', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, ($i % 300) * 3.0, 0.0, intdiv($i, 300) * 3.0, 1.0);
    }

    return implode('', $out);
};

foreach ([10_000, 100_000] as $n) {
    $packed = $row($n);
    printf("-- %d instances (%.1f MB) in one buffer --\n", $n, strlen($packed) / 1048576);
    printf("  extentsOfPacked (worker-side)  %s\n", summarize(sample(fn () => BatchBounds::extentsOfPacked($packed, $n), 5)));

    $b = new CellInstanceBuffers();
    printf("  set() with measuring           %s\n", summarize(sample(fn () => $b->set('c', 'bench_box', 'm', $packed), 5)));
    $ext = BatchBounds::extentsOfPacked($packed, $n);
    printf("  set() with worker extents      %s\n", summarize(sample(fn () => $b->set('c', 'bench_box', 'm', $packed, $ext), 9)));
    printf("  commands(), first admission    %s\n", summarize(sample(function () use ($b, $packed, $ext) {
        $b->set('c', 'bench_box', 'm', $packed, $ext);
        $b->commands();
    }, 9)));
    $b->commands();
    printf("  commands(), nothing changed    %s\n", summarize(sample(fn () => $b->commands(), 200)));

    $m = Mat4::translation(1.0, 2.0, 3.0);
    // Each edit followed by an admission, as in a frame loop: the admitted snapshot shares the string,
    // so the edit copies it once.
    printf("  append + commands()            %s\n", summarize(sample(function () use ($b, $m) {
        $b->append('c', 'bench_box', 'm', $m);
        $b->commands();
    }, 30)));
    printf("  replaceAt + commands()         %s\n", summarize(sample(function () use ($b, $m) {
        $b->replaceAt('c', 'bench_box', 'm', 5, $m);
        $b->commands();
    }, 30)));
    printf("  removeAt(0) + commands()       %s\n", summarize(sample(function () use ($b) {
        $b->removeAt('c', 'bench_box', 'm', 0);
        $b->commands();
    }, 30)));
    printf("  1000 appends, no commands()    %s\n", summarize(sample(function () use ($b, $m) {
        for ($i = 0; $i < 1000; $i++) {
            $b->append('c', 'bench_box', 'm', $m);
        }
    }, 5)));
}

// A resident world: 100 cells x 10,000 instances = 1,000,000 instances.
$before = memory_get_usage();
$world = new CellInstanceBuffers();
$cell = $row(10_000);
$ext = BatchBounds::extentsOfPacked($cell, 10_000);
// distinct strings per cell, as real cells would hold
for ($c = 0; $c < 100; $c++) {
    $world->set("cell{$c}", 'bench_box', 'm', substr($cell, 0, -64) . pack('g16', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, (float) $c, 0.0, 0.0, 1.0), $ext);
}
printf("-- resident world: %d instances in %d cells: %.1f MB of buffer strings, %.1f MB PHP memory delta --\n",
    $world->instanceCount(), count($world->cells()), $world->byteSize() / 1048576, (memory_get_usage() - $before) / 1048576);
$cells = $world->cells();
$zero = str_repeat("\0", 64 * 10_000);
$zeroExt = BatchBounds::extentsOfPacked($zero, 10_000);
printf("  100 x set() with worker extents + first commands()   %s\n", summarize(sample(function () use ($cells, $zero, $zeroExt) {
    $w = new CellInstanceBuffers();
    foreach ($cells as $c) {
        $w->set($c, 'bench_box', 'm', $zero, $zeroExt);
    }
    $w->commands();
}, 5)));
$near = array_slice($cells, 0, 25);
$world->commands();
printf("  commands() steady, 25 visible cells                  %s\n", summarize(sample(fn () => $world->commands($near), 200)));
printf("  commands() steady, 100 visible cells                 %s\n", summarize(sample(fn () => $world->commands($cells), 200)));
