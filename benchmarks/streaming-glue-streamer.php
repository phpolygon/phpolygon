<?php

declare(strict_types=1);

// Section "streamer" of benchmarks/streaming-glue.php (required by it, helpers come from there).

use PHPolygon\Streaming\CellStreamer;
use PHPolygon\Streaming\CellStreamerConfig;
use PHPolygon\Tests\Streaming\Jobs\CellBakeJob;
use PHPolygon\Thread\AsyncJobPool;

echo "\n== CellStreamer (glue only: trivial handler, trivial install, inline pool) ==\n";

foreach ([[2, 'radius 2'], [4, 'radius 4']] as [$radius, $label]) {
    $pool = new AsyncJobPool(0, '512M', 30.0, 2, 4);
    $pool->register('bake', CellBakeJob::class . '::bake');
    $installed = 0;
    $streamer = new CellStreamer(
        new CellStreamerConfig(
            cellSize: 100.0,
            loadRadius: $radius,
            unloadRadius: $radius + 1,
            lodByRing: [0, 1, 2],
            prefetchRadius: 1,
            maxInFlight: 16,
            maxRequestsPerFrame: 4,
            uploadBudgetBytes: 1 << 30,
            frameBudgetMs: 2.0,
            gridWidth: 200,
            gridHeight: 40,
        ),
        $pool,
        'bake',
        static fn (int $cx, int $cz, int $lod, int $epoch): array => [$cx, $cz, $lod, $epoch],
        static function () use (&$installed): void { $installed++; },
        static function (): void {},
    );

    // Walk 1500 frames along x at 10 u/frame (a cell border every 10 frames), measuring each update().
    $walk = [];
    for ($f = 0; $f < 1500; $f++) {
        $t = hrtime(true);
        $streamer->update(150.0 + $f * 10.0, 2050.0);
        $walk[] = (hrtime(true) - $t) / 1e6;
    }
    printf("%s: update() while walking, 1500 frames: %s; worst %.3f ms; installed %d cells, resident %d\n",
        $label, summarize($walk), max($walk), $installed, count($streamer->residentCells()));

    // Settled and standing still.
    for ($f = 0; $f < 50; $f++) {
        $streamer->update(150.0 + 1499 * 10.0, 2050.0);
    }
    $still = [];
    for ($f = 0; $f < 2000; $f++) {
        $t = hrtime(true);
        $streamer->update(150.0 + 1499 * 10.0, 2050.0);
        $still[] = (hrtime(true) - $t) / 1e6;
    }
    printf("%s: update() settled and standing still: %s\n", $label, summarize($still));
}
