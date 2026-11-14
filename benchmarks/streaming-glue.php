<?php

declare(strict_types=1);

/**
 * Cost of the world-streaming building blocks: packed mesh hand-off, the async
 * job pool, instance buffers, collider grid, the cell streamer glue.
 *
 *   php -d memory_limit=2G benchmarks/streaming-glue.php [section ...]
 *
 * Sections: mesh, jobs, latency, instances, colliders, streamer (default: all).
 * Prints median / p90 over repeated runs; numbers depend on the machine, quote
 * them with the date and PHP build.
 */

use PHPolygon\Geometry\PackedMeshData;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Simulation\ChannelTransport;
use PHPolygon\Simulation\FarSimulationSubsystem;
use PHPolygon\Tests\Simulation\GrowthTestKernel;
use PHPolygon\Tests\Thread\Jobs\StreamingTestJobs;
use PHPolygon\Thread\AsyncJobPool;
use PHPolygon\Thread\ThreadScheduler;

require __DIR__ . '/../vendor/autoload.php';

/** @param list<float> $samples */
function summarize(array $samples): string
{
    sort($samples);
    $n = count($samples);

    return sprintf('median %.3f ms, p90 %.3f ms, min %.3f ms (n=%d)', $samples[intdiv($n, 2)], $samples[(int) floor($n * 0.9)], $samples[0], $n);
}

/** @return list<float> milliseconds */
function sample(callable $fn, int $reps): array
{
    $out = [];
    for ($i = 0; $i < $reps; $i++) {
        $t = hrtime(true);
        $fn();
        $out[] = (hrtime(true) - $t) / 1e6;
    }

    return $out;
}

$sections = array_slice($argv, 1) ?: ['mesh', 'jobs', 'latency', 'instances', 'colliders', 'streamer'];
echo 'PHP ', PHP_VERSION, ' ', PHP_ZTS ? 'ZTS' : 'NTS', ' ', PHP_OS_FAMILY, ' ', date('Y-m-d'), "\n";

if (in_array('mesh', $sections, true)) {
    echo "\n== PackedMeshData ==\n";
    foreach ([[16, 16], [40, 40], [100, 100], [200, 200]] as [$stacks, $slices]) {
        $mesh = PackedMeshData::fromMeshData(SphereMesh::generate(1.0, $stacks, $slices))->toMeshData();
        $p = PackedMeshData::fromMeshData($mesh);
        $bytes = $p->pack();
        $mb = strlen($bytes) / 1048576;
        printf("%d vertices, %d triangles, %.2f MB packed\n", $p->vertexCount, $p->triangleCount(), $mb);
        printf("  fromMeshData  %s\n", summarize(sample(fn () => PackedMeshData::fromMeshData($mesh), 7)));
        printf("  pack          %s\n", summarize(sample(fn () => $p->pack(), 15)));
        printf("  unpack        %s\n", summarize(sample(fn () => PackedMeshData::unpack($bytes), 15)));
        printf("  toMeshData    %s   -> %.2f ms per MB\n", summarize(sample(fn () => $p->toMeshData(), 7)), array_sum(sample(fn () => $p->toMeshData(), 5)) / 5 / $mb);
        printf("  gpuVertexBytes%s\n", summarize(sample(fn () => $p->gpuVertexBytes(), 5)));
    }
}

if (in_array('jobs', $sections, true) || in_array('latency', $sections, true)) {
    echo "\n== AsyncJobPool ==\n";
    $make = static function (int $workers, int $depth): AsyncJobPool {
        $pool = new AsyncJobPool($workers, '512M', 30.0, $depth);
        $pool->register('echo', StreamingTestJobs::class . '::echo');
        $pool->register('pack', StreamingTestJobs::class . '::packFloats');
        $pool->register('spin', StreamingTestJobs::class . '::spin');

        return $pool;
    };

    foreach ([1, 2] as $depth) {
        $pool = $make(1, $depth);
        $pool->submit('warm', 0, 0, 'echo', 1);
        $pool->drain(10.0);
        $lat = [];
        for ($i = 0; $i < 200; $i++) {
            $t = hrtime(true);
            $pool->submit("k{$i}", 0, 1, 'echo', $i);
            do {
                $r = $pool->collect();
            } while ($r === []);
            $lat[] = (hrtime(true) - $t) / 1e6;
        }
        printf("submit -> collected, trivial job, 1 worker, depth %d (hot poll): %s\n", $depth, summarize($lat));
        $pool->shutdown();
    }

    $pool = $make(1, 1);
    $pool->submit('busy', 0, 1, 'spin', 300);
    $pool->collect();
    $idle = sample(fn () => $pool->collect(), 2000);
    printf("collect() with a busy worker and nothing finished: %s\n", summarize($idle));
    $pool->drain(5.0);
    $pool->shutdown();

    foreach ([[0, 'inline'], [1, '1 worker'], [2, '2 workers'], [4, '4 workers']] as [$workers, $label]) {
        $pool = $make($workers, 2);
        $pool->submit('warm', 0, 0, 'echo', 1);
        $pool->drain(10.0);
        $t = hrtime(true);
        for ($i = 0; $i < 64; $i++) {
            $pool->submit("p{$i}", 0, 1, 'pack', 50_000); // 200 KB result each
        }
        $n = count($pool->drain(60.0));
        printf("64 jobs x (50k floats packed, 200 KB result), %s: %.1f ms total (%d done)\n", $label, (hrtime(true) - $t) / 1e6, $n);
        $pool->shutdown();
    }
}

if (in_array('latency', $sections, true)) {
    echo "\n== ChannelTransport submit/collect (existing path, for comparison) ==\n";
    $scheduler = new ThreadScheduler(1);
    $scheduler->register('far', FarSimulationSubsystem::class);
    $scheduler->boot();
    $transport = new ChannelTransport($scheduler, 'far', new \PHPolygon\ECS\World());
    $transport->step(new GrowthTestKernel(), [1 => ['height' => 0.0, 'rate' => 2.0]], 0.1);
    $lat = [];
    for ($i = 0; $i < 40; $i++) {
        $t = hrtime(true);
        $token = $transport->submit(new GrowthTestKernel(), [1 => ['height' => 0.0, 'rate' => 2.0]], 1, 0.1);
        do {
            $r = $transport->collect($token);
        } while ($r === null);
        $lat[] = (hrtime(true) - $t) / 1e6;
    }
    printf("submit -> collected, trivial kernel (hot poll): %s\n", summarize($lat));
    $scheduler->shutdown();
}

foreach (['instances', 'colliders', 'streamer'] as $name) {
    $file = __DIR__ . "/streaming-glue-{$name}.php";
    if (in_array($name, $sections, true) && is_file($file)) {
        require $file;
    }
}
