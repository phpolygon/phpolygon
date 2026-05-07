<?php

declare(strict_types=1);

/**
 * PHPolygon benchmark CLI.
 *
 * Usage:
 *   php benchmarks/run.php <scenario>            run + write results
 *   php benchmarks/run.php <scenario> --frames 1200
 *   php benchmarks/run.php <scenario> --warmup 120
 *   php benchmarks/run.php <scenario> --compare HEAD~1
 *   php benchmarks/run.php <scenario> --accept   write baseline
 *   php benchmarks/run.php list                  list available scenarios
 *
 * Scenarios:
 *   empty-scene, boxes-1000, boxes-1000-instanced, mixed-scene,
 *   mesh-gen-stress, physics-stack
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Scenario.php';
require __DIR__ . '/BenchRunner.php';
foreach (\glob(__DIR__ . '/scenarios/*.php') as $f) {
    require $f;
}

use PHPolygon\Benchmarks\BenchRunner;
use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Benchmarks\Scenarios\Boxes1000;
use PHPolygon\Benchmarks\Scenarios\Boxes1000Instanced;
use PHPolygon\Benchmarks\Scenarios\EmptyScene;
use PHPolygon\Benchmarks\Scenarios\MeshGenStress;
use PHPolygon\Benchmarks\Scenarios\MixedScene;
use PHPolygon\Benchmarks\Scenarios\PhysicsStack;

const SCENARIOS = [
    'empty-scene' => EmptyScene::class,
    'boxes-1000' => Boxes1000::class,
    'boxes-1000-instanced' => Boxes1000Instanced::class,
    'mixed-scene' => MixedScene::class,
    'mesh-gen-stress' => MeshGenStress::class,
    'physics-stack' => PhysicsStack::class,
];

$args = \array_slice($argv, 1);
$scenarioName = $args[0] ?? null;

if ($scenarioName === null || $scenarioName === 'list' || $scenarioName === '--help' || $scenarioName === '-h') {
    \fwrite(STDOUT, "Available scenarios:\n");
    foreach (\array_keys(SCENARIOS) as $name) {
        \fwrite(STDOUT, "  - {$name}\n");
    }
    exit(0);
}

if (!isset(SCENARIOS[$scenarioName])) {
    \fwrite(STDERR, "Unknown scenario: {$scenarioName}\n");
    \fwrite(STDERR, "Run 'php benchmarks/run.php list' to see available scenarios.\n");
    exit(1);
}

$frames = 600;
$warmup = 60;
$compareRef = null;
$accept = false;

for ($i = 1; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--frames') {
        $frames = (int) ($args[++$i] ?? '600');
    } elseif ($arg === '--warmup') {
        $warmup = (int) ($args[++$i] ?? '60');
    } elseif ($arg === '--compare') {
        $compareRef = $args[++$i] ?? null;
    } elseif ($arg === '--accept') {
        $accept = true;
    } else {
        \fwrite(STDERR, "Unknown flag: {$arg}\n");
        exit(1);
    }
}

$class = SCENARIOS[$scenarioName];
/** @var Scenario $scenario */
$scenario = new $class();

\fwrite(STDOUT, "Running {$scenarioName} ({$warmup} warmup + {$frames} measure frames)...\n");

$runner = new BenchRunner(warmupFrames: $warmup, measureFrames: $frames);
$result = $runner->run($scenario);

printSummary($result);

$resultsDir = __DIR__ . '/results';
@\mkdir($resultsDir, 0755, true);

$resultPath = $resultsDir . "/{$scenarioName}-{$result['gitSha']}.json";
\file_put_contents($resultPath, \json_encode($result, JSON_PRETTY_PRINT) . "\n");
\fwrite(STDOUT, "\nResults: {$resultPath}\n");

if ($accept) {
    $baselineDir = __DIR__ . '/baselines';
    @\mkdir($baselineDir, 0755, true);
    $baselinePath = $baselineDir . "/{$scenarioName}.json";
    \file_put_contents($baselinePath, \json_encode($result, JSON_PRETTY_PRINT) . "\n");
    \fwrite(STDOUT, "Baseline updated: {$baselinePath}\n");
}

if ($compareRef !== null) {
    compareAgainstRef($result, $scenarioName, $compareRef);
}

function printSummary(array $r): void
{
    $f = $r['frame'];
    \fwrite(STDOUT, "\n");
    \fwrite(STDOUT, \sprintf("  fps:     %7.1f (mean: %5.2fms, p50: %5.2fms, p95: %5.2fms, p99: %5.2fms)\n",
        $f['fps'], $f['meanMs'], $f['p50Ms'], $f['p95Ms'], $f['p99Ms']));
    \fwrite(STDOUT, \sprintf("  range:   %.2fms - %.2fms\n", $f['minMs'], $f['maxMs']));
    \fwrite(STDOUT, \sprintf("  gc:      %d runs, %d collected\n", $r['gc']['runs'], $r['gc']['collected']));
    \fwrite(STDOUT, "\n  Top sections (by total time):\n");

    $i = 0;
    foreach ($r['sections'] as $name => $info) {
        if ($i++ >= 10) break;
        \fwrite(STDOUT, \sprintf("    %-32s  %8.3fms total / %4d calls / %.4fms avg\n",
            $name, $info['totalMs'], $info['calls'], $info['avgMs']));
    }
}

function compareAgainstRef(array $current, string $scenarioName, string $ref): void
{
    $sha = \trim((string) @\shell_exec("git rev-parse --short {$ref} 2>/dev/null"));
    if ($sha === '') {
        \fwrite(STDERR, "Could not resolve git ref: {$ref}\n");
        return;
    }
    $path = __DIR__ . "/results/{$scenarioName}-{$sha}.json";
    if (!\file_exists($path)) {
        \fwrite(STDERR, "No prior result for {$scenarioName} at {$sha}: {$path}\n");
        return;
    }
    $prior = \json_decode((string) \file_get_contents($path), true);
    if (!\is_array($prior)) {
        \fwrite(STDERR, "Could not parse {$path}\n");
        return;
    }

    \fwrite(STDOUT, "\n  vs {$sha}:\n");
    foreach (['p50Ms', 'p95Ms', 'p99Ms', 'meanMs'] as $key) {
        $a = (float) $prior['frame'][$key];
        $b = (float) $current['frame'][$key];
        $delta = $a > 0.0 ? (($b - $a) / $a) * 100.0 : 0.0;
        $sign = $delta >= 0.0 ? '+' : '';
        $marker = $delta > 5.0 ? '  WORSE' : ($delta < -5.0 ? '  BETTER' : '');
        \fwrite(STDOUT, \sprintf("    %-7s  %.2fms -> %.2fms  (%s%.1f%%)%s\n", $key, $a, $b, $sign, $delta, $marker));
    }
}
