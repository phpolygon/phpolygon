<?php

declare(strict_types=1);

/**
 * PHPolygon memory profiling CLI. Companion to benchmarks/run.php
 * (which measures CPU). This one walks each scenario through key
 * lifecycle milestones and reports PHP-side memory deltas so we can
 * pinpoint *where* the engine allocates rather than how fast it
 * runs.
 *
 * Usage:
 *   php benchmarks/memory.php <scenario>     # one scenario, console report
 *   php benchmarks/memory.php all            # every scenario, write JSON
 *   php benchmarks/memory.php list
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Scenario.php';
require __DIR__ . '/BenchRunner.php';
foreach (\glob(__DIR__ . '/scenarios/*.php') as $f) {
    require $f;
}

use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Benchmarks\Scenarios\Boxes1000;
use PHPolygon\Benchmarks\Scenarios\Boxes1000Instanced;
use PHPolygon\Benchmarks\Scenarios\EmptyScene;
use PHPolygon\Benchmarks\Scenarios\MeshGenStress;
use PHPolygon\Benchmarks\Scenarios\MixedScene;
use PHPolygon\Benchmarks\Scenarios\PhysicsStack;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\MemoryProfiler;

const SCENARIOS = [
    'empty-scene'          => EmptyScene::class,
    'boxes-1000'           => Boxes1000::class,
    'boxes-1000-instanced' => Boxes1000Instanced::class,
    'mixed-scene'          => MixedScene::class,
    'mesh-gen-stress'      => MeshGenStress::class,
    'physics-stack'        => PhysicsStack::class,
];

$args = \array_slice($argv, 1);
$scenarioName = $args[0] ?? null;

if ($scenarioName === null || $scenarioName === '--help' || $scenarioName === '-h') {
    \fwrite(STDOUT, "Usage: php benchmarks/memory.php <scenario>|all|list\n");
    exit(0);
}

if ($scenarioName === 'list') {
    \fwrite(STDOUT, "Available scenarios:\n");
    foreach (\array_keys(SCENARIOS) as $name) {
        \fwrite(STDOUT, "  - {$name}\n");
    }
    exit(0);
}

if ($scenarioName === 'all') {
    // Cross-scenario contamination guard: if we ran every scenario in
    // the same process, the autoload pool, opcache, and class registry
    // for scenario N would inflate the "startup" milestone of N+1.
    // Re-exec ourselves once per scenario so each one sees a clean
    // process baseline, then aggregate the JSON output.
    $self = (string) ($argv[0] ?? __FILE__);
    $results = [];
    foreach (\array_keys(SCENARIOS) as $name) {
        \fwrite(STDOUT, "\n=== {$name} ===\n");
        $output = (string) \shell_exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($self) . ' ' . \escapeshellarg($name) . ' 2>&1');
        \fwrite(STDOUT, $output);
        if (\preg_match('/^JSON: (.+)$/m', $output, $m)) {
            $decoded = \json_decode($m[1], true);
            if (\is_array($decoded)) {
                $results[$name] = ['milestones' => $decoded];
            }
        }
    }
    $resultsDir = __DIR__ . '/memory-results';
    @\mkdir($resultsDir, 0755, true);
    $sha = trim((string) \shell_exec('git rev-parse --short HEAD 2>/dev/null')) ?: 'unknown';
    $path = $resultsDir . "/memory-{$sha}.json";
    \file_put_contents($path, \json_encode($results, JSON_PRETTY_PRINT) . "\n");
    \fwrite(STDOUT, "\nWrote consolidated report: {$path}\n");
    exit(0);
}

if (!isset(SCENARIOS[$scenarioName])) {
    \fwrite(STDERR, "Unknown scenario: {$scenarioName}\n");
    exit(1);
}

\fwrite(STDOUT, "=== {$scenarioName} ===\n");
$report = profileScenario(SCENARIOS[$scenarioName]);
\fwrite(STDOUT, $report['report']);

/**
 * @param class-string<Scenario> $scenarioClass
 * @return array{milestones: list<array{name:string, usage:int, peak:int, gcRuns:int, t:float}>, report: string}
 */
function profileScenario(string $scenarioClass): array
{
    \mt_srand(424242);

    $profiler = new MemoryProfiler();
    $profiler->milestone('autoload');

    $engine = new Engine(new EngineConfig(
        headless: true,
        is3D: true,
        renderBackend3D: 'null',
        firstLaunchCalibration: false,
    ));
    $profiler->milestone('engine.constructed');

    /** @var Scenario $scenario */
    $scenario = new $scenarioClass();
    $scenario->setUp($engine);
    $profiler->milestone('scenario.setup');

    $world = $engine->world;
    $dt = 1.0 / 60.0;

    // Single warm-up frame to flush lazy state, mesh upload, etc.
    tickFrame($engine, $scenario, 0, $dt);
    $profiler->milestone('frame.1');

    // 60 measured frames - matches the scenario benchmark warmup.
    for ($f = 1; $f <= 60; $f++) {
        tickFrame($engine, $scenario, $f, $dt);
    }
    $profiler->milestone('frames.61_steady');

    // Drop the engine and its world to see what the process retains
    // beyond the scene (caches, registries etc.).
    unset($engine, $world, $scenario);
    $profiler->milestone('engine.released');

    return [
        'milestones' => $profiler->milestones(),
        'report'     => $profiler->report(),
    ];
}

/**
 * Same per-frame sequence the BenchRunner uses; duplicated rather
 * than imported so memory.php stays standalone (the runner has its
 * own PerfProfiler instrumentation that would noise this report).
 */
function tickFrame(Engine $engine, Scenario $scenario, int $frame, float $dt): void
{
    $engine->world->update($dt);
    $scenario->tickFrame($engine, $frame, $dt);
    if ($engine->renderer3D !== null) {
        $engine->renderer3D->beginFrame();
    }
    $engine->renderer2D->beginFrame();
    $engine->world->render();
    if ($engine->renderer3D !== null) {
        $engine->renderer3D->endFrame();
    }
    $engine->renderer2D->endFrame();
}
