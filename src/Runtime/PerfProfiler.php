<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Lightweight profiling facade for Engine and Game code.
 *
 * Use begin()/end() pairs or section() around hot paths. When no profiling
 * extension is active, every call is a single bool check and a no-op so the
 * markers stay safe to leave in shipping code.
 *
 * Activation:
 *   - SPX:     SPX_ENABLED=1 php examples/...
 *   - Excimer: PHPOLYGON_EXCIMER=1 php examples/...
 *   - Both off in production. Status frozen at first access.
 *
 * Section names use dot notation, e.g. "render3d.flush", "ecs.system.PhysicsSystem".
 */
final class PerfProfiler
{
    private const BACKEND_NONE = 0;
    private const BACKEND_SPX = 1;
    private const BACKEND_EXCIMER = 2;

    private static int $backend = -1;

    /** @var array<string, array{0:int,1:int}> Per-section accumulator: [callCount, totalNanos] */
    private static array $sections = [];

    /** @var array<int, array{name:string, start:int}> Open begin() stack */
    private static array $stack = [];

    private static ?\ExcimerProfiler $excimer = null;

    /** @var array{0:int,1:int}|null Last GC snapshot: [runs, collected] */
    private static ?array $gcBaseline = null;

    public static function isActive(): bool
    {
        return self::backend() !== self::BACKEND_NONE;
    }

    public static function begin(string $section): void
    {
        if (self::backend() === self::BACKEND_NONE) {
            return;
        }

        self::$stack[] = ['name' => $section, 'start' => (int) hrtime(true)];

        if (self::$backend === self::BACKEND_SPX && \function_exists('spx_report_user_event')) {
            \spx_report_user_event($section . '.begin');
        }
    }

    public static function end(): void
    {
        if (self::backend() === self::BACKEND_NONE || self::$stack === []) {
            return;
        }

        $frame = \array_pop(self::$stack);
        $name = $frame['name'];
        $elapsed = (int) hrtime(true) - $frame['start'];

        if (!isset(self::$sections[$name])) {
            self::$sections[$name] = [0, 0];
        }
        self::$sections[$name][0]++;
        self::$sections[$name][1] += $elapsed;

        if (self::$backend === self::BACKEND_SPX && \function_exists('spx_report_user_event')) {
            \spx_report_user_event($name . '.end');
        }
    }

    /**
     * Run $fn inside a begin/end pair. Returns whatever $fn returns.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function section(string $name, callable $fn): mixed
    {
        if (self::backend() === self::BACKEND_NONE) {
            return $fn();
        }

        self::begin($name);
        try {
            return $fn();
        } finally {
            self::end();
        }
    }

    /**
     * Captures GC counters; call once per frame. Returns delta since previous
     * call as ['runs' => int, 'collected' => int]. First call returns zeros.
     *
     * @return array{runs:int, collected:int}
     */
    public static function gcDelta(): array
    {
        $status = \gc_status();
        $runs = $status['runs'];
        $collected = $status['collected'];

        if (self::$gcBaseline === null) {
            self::$gcBaseline = [$runs, $collected];
            return ['runs' => 0, 'collected' => 0];
        }

        $delta = [
            'runs' => $runs - self::$gcBaseline[0],
            'collected' => $collected - self::$gcBaseline[1],
        ];
        self::$gcBaseline = [$runs, $collected];
        return $delta;
    }

    /**
     * Returns accumulated per-section stats. Useful for benchmark runners
     * that want a programmatic view independent of SPX's web UI.
     *
     * @return array<string, array{calls:int, totalNs:int, avgNs:float}>
     */
    public static function snapshot(): array
    {
        $out = [];
        foreach (self::$sections as $name => [$calls, $totalNs]) {
            $out[$name] = [
                'calls' => $calls,
                'totalNs' => $totalNs,
                'avgNs' => $calls > 0 ? $totalNs / $calls : 0.0,
            ];
        }
        return $out;
    }

    public static function reset(): void
    {
        self::$sections = [];
        self::$stack = [];
        self::$gcBaseline = null;
    }

    /**
     * Starts the Excimer sampling profiler. Call once at engine start when
     * PHPOLYGON_EXCIMER=1. Output is written via stop() to a Speedscope JSON.
     */
    public static function startExcimer(float $samplingPeriodSeconds = 0.001): void
    {
        if (self::backend() !== self::BACKEND_EXCIMER || self::$excimer !== null) {
            return;
        }

        $profiler = new \ExcimerProfiler();
        $profiler->setPeriod($samplingPeriodSeconds);
        $profiler->setEventType(\EXCIMER_REAL);
        $profiler->start();
        self::$excimer = $profiler;
    }

    /**
     * Stops Excimer and writes a Speedscope-compatible JSON to $path.
     * Drop the file at https://www.speedscope.app to view a flamegraph.
     */
    public static function stopExcimer(string $path): void
    {
        if (self::$excimer === null) {
            return;
        }

        self::$excimer->stop();
        $log = self::$excimer->getLog();
        \file_put_contents($path, $log->formatCollapsed());
        self::$excimer = null;
    }

    private static function backend(): int
    {
        if (self::$backend !== -1) {
            return self::$backend;
        }

        if (\extension_loaded('spx') && \getenv('SPX_ENABLED') === '1') {
            self::$backend = self::BACKEND_SPX;
        } elseif (\extension_loaded('excimer') && \getenv('PHPOLYGON_EXCIMER') === '1') {
            self::$backend = self::BACKEND_EXCIMER;
        } else {
            self::$backend = self::BACKEND_NONE;
        }

        return self::$backend;
    }
}
