<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Headless frame-loop benchmark runner.
 *
 * Drives a Scenario through warmup + measure phases without entering the
 * full Engine::run() game loop. Each measure iteration runs World::update()
 * and World::render() inside engine.update / engine.render markers. Per-frame
 * wall time is captured separately so the report stays meaningful even when
 * no profiler extension is loaded.
 *
 * Output: a JSON document under benchmarks/results/<scenario>-<sha>.json with
 * frame time percentiles, GC delta totals, and per-section averages.
 */
final class BenchRunner
{
    public function __construct(
        private readonly int $warmupFrames = 60,
        private readonly int $measureFrames = 600,
        private readonly float $dt = 1.0 / 60.0,
    ) {}

    /**
     * @return array{
     *     scenario:string,
     *     gitSha:string,
     *     warmupFrames:int,
     *     measureFrames:int,
     *     dt:float,
     *     frame:array{p50Ms:float, p95Ms:float, p99Ms:float, meanMs:float, minMs:float, maxMs:float, fps:float},
     *     gc:array{runs:int, collected:int},
     *     sections:array<string, array{calls:int, totalMs:float, avgMs:float}>,
     * }
     */
    public function run(Scenario $scenario): array
    {
        // Seed deterministically so any rand() inside the scenario matches
        // across runs. PHP's mt_srand is global - this affects the whole run.
        \mt_srand(424242);

        $engine = new Engine(new EngineConfig(
            headless: true,
            is3D: true,
            renderBackend3D: 'null',
        ));

        $scenario->setUp($engine);

        // Warmup - JIT, class autoload, mesh upload, first-frame caches
        PerfProfiler::reset();
        for ($i = 0; $i < $this->warmupFrames; $i++) {
            $this->tick($engine, $scenario, $i);
        }

        // Measure
        PerfProfiler::reset();
        $gcRuns = 0;
        $gcCollected = 0;
        $frameTimes = [];

        for ($i = 0; $i < $this->measureFrames; $i++) {
            $start = (int) hrtime(true);
            $this->tick($engine, $scenario, $this->warmupFrames + $i);
            $elapsedNs = (int) hrtime(true) - $start;
            $frameTimes[] = $elapsedNs / 1_000_000.0;

            $delta = PerfProfiler::gcDelta();
            $gcRuns += $delta['runs'];
            $gcCollected += $delta['collected'];
        }

        return [
            'scenario' => $scenario->name(),
            'gitSha' => self::resolveGitSha(),
            'warmupFrames' => $this->warmupFrames,
            'measureFrames' => $this->measureFrames,
            'dt' => $this->dt,
            'frame' => self::framePercentiles($frameTimes),
            'gc' => ['runs' => $gcRuns, 'collected' => $gcCollected],
            'sections' => self::sectionStats(),
        ];
    }

    private function tick(Engine $engine, Scenario $scenario, int $frame): void
    {
        PerfProfiler::begin('engine.update');
        $engine->world->update($this->dt);
        $scenario->tickFrame($engine, $frame, $this->dt);
        PerfProfiler::end();

        PerfProfiler::begin('engine.render');
        if ($engine->renderer3D !== null) {
            $engine->renderer3D->beginFrame();
        }
        $engine->renderer2D->beginFrame();
        $engine->world->render();
        if ($engine->renderer3D !== null) {
            PerfProfiler::begin('render3d.flush');
            $engine->renderer3D->endFrame();
            PerfProfiler::end();
        }
        $engine->renderer2D->endFrame();
        PerfProfiler::end();
    }

    /**
     * @param list<float> $times
     * @return array{p50Ms:float, p95Ms:float, p99Ms:float, meanMs:float, minMs:float, maxMs:float, fps:float}
     */
    private static function framePercentiles(array $times): array
    {
        if ($times === []) {
            return ['p50Ms' => 0.0, 'p95Ms' => 0.0, 'p99Ms' => 0.0, 'meanMs' => 0.0, 'minMs' => 0.0, 'maxMs' => 0.0, 'fps' => 0.0];
        }

        $sorted = $times;
        \sort($sorted);
        $count = count($sorted);
        $mean = \array_sum($sorted) / $count;

        return [
            'p50Ms' => self::percentile($sorted, 0.50),
            'p95Ms' => self::percentile($sorted, 0.95),
            'p99Ms' => self::percentile($sorted, 0.99),
            'meanMs' => $mean,
            'minMs' => $sorted[0],
            'maxMs' => $sorted[$count - 1],
            'fps' => $mean > 0.0 ? 1000.0 / $mean : 0.0,
        ];
    }

    /**
     * @param list<float> $sorted
     */
    private static function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $idx = (int) \min($count - 1, \floor($p * ($count - 1)));
        return $sorted[$idx];
    }

    /**
     * @return array<string, array{calls:int, totalMs:float, avgMs:float}>
     */
    private static function sectionStats(): array
    {
        $snapshot = PerfProfiler::snapshot();
        $out = [];
        foreach ($snapshot as $name => $info) {
            $out[$name] = [
                'calls' => $info['calls'],
                'totalMs' => $info['totalNs'] / 1_000_000.0,
                'avgMs' => $info['avgNs'] / 1_000_000.0,
            ];
        }
        \uasort($out, static fn(array $a, array $b): int => $b['totalMs'] <=> $a['totalMs']);
        return $out;
    }

    private static function resolveGitSha(): string
    {
        $sha = @\shell_exec('git rev-parse --short HEAD 2>/dev/null');
        if (!\is_string($sha) || \trim($sha) === '') {
            return 'unknown';
        }
        return \trim($sha);
    }
}
