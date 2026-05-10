<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Milestone-based PHP-memory profiler. Companion to {@see PerfProfiler}
 * (which measures CPU time) - this one measures the resident PHP heap
 * at named points in a scene's lifecycle so we can pinpoint *where*
 * the engine allocates rather than just *how much* peak it reaches.
 *
 * Usage:
 *
 * ```php
 * $profiler = new MemoryProfiler();
 * $profiler->milestone('engine.constructed');
 *
 * $engine->onInit(...);
 * $profiler->milestone('scene.built');
 *
 * for ($f = 0; $f < 60; $f++) { $engine->renderFrame(); }
 * $profiler->milestone('60_frames.warm');
 *
 * print $profiler->report();
 * ```
 *
 * Each `milestone()` call:
 *   - runs `gc_collect_cycles()` so cycle-trapped allocations are
 *     released before the snapshot (otherwise short-lived closures
 *     and refs inflate the reading);
 *   - records `memory_get_usage(true)` (PHP-allocated bytes,
 *     including PHP's internal pool overhead);
 *   - records `memory_get_peak_usage(true)` (high-water mark since
 *     the last reset).
 *
 * Limitations:
 *   - PHP-side only: GPU memory (textures, FBOs, instance buffers)
 *     is invisible. Use the platform's GPU profiler for that.
 *   - Won't tell you *what* is in memory, only *how much*. Pair with
 *     `gc_status()['runs']` and per-class instance counts (via
 *     `Counter` helper) when you need attribution.
 */
final class MemoryProfiler
{
    /** @var list<array{name: string, usage: int, peak: int, gcRuns: int, t: float}> */
    private array $milestones = [];

    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->milestone('startup');
    }

    /**
     * Snapshot resident + peak memory under a label. Forces a GC cycle
     * pass first so the reading reflects steady state, not transient
     * cycle-trapped allocations.
     */
    public function milestone(string $name): void
    {
        gc_collect_cycles();
        $this->milestones[] = [
            'name'   => $name,
            // real_usage=false reports the bytes actually requested via
            // emalloc(), in single-byte resolution. real_usage=true would
            // report the OS-page-sized chunks PHP grabs from the system,
            // which rounds to 2-4 MB and hides everything below that.
            'usage'  => memory_get_usage(false),
            'peak'   => memory_get_peak_usage(false),
            'gcRuns' => gc_status()['runs'],
            't'      => microtime(true) - $this->startTime,
        ];
    }

    /**
     * @return list<array{name: string, usage: int, peak: int, gcRuns: int, t: float}>
     */
    public function milestones(): array
    {
        return $this->milestones;
    }

    /**
     * Render a human-readable table of milestones with per-step deltas.
     * Output is plain text so it round-trips well into commit messages
     * and PR descriptions; the trailing JSON line carries the same
     * data for tooling.
     */
    public function report(): string
    {
        if ($this->milestones === []) {
            return "MemoryProfiler: no milestones recorded.\n";
        }

        $lines = [];
        $lines[] = sprintf(
            "%-32s %12s %12s %12s %10s",
            'milestone',
            'usage',
            'peak',
            'Δ usage',
            't (s)',
        );
        $lines[] = str_repeat('-', 80);

        $prevUsage = 0;
        foreach ($this->milestones as $m) {
            $delta = $m['usage'] - $prevUsage;
            $lines[] = sprintf(
                "%-32s %12s %12s %12s %10.3f",
                $m['name'],
                self::formatBytes($m['usage']),
                self::formatBytes($m['peak']),
                ($delta >= 0 ? '+' : '') . self::formatBytes($delta),
                $m['t'],
            );
            $prevUsage = $m['usage'];
        }

        $lines[] = '';
        $lines[] = 'JSON: ' . (string) json_encode($this->milestones);
        return implode("\n", $lines) . "\n";
    }

    private static function formatBytes(int $bytes): string
    {
        $abs = abs($bytes);
        if ($abs >= 1024 * 1024) {
            return sprintf('%.2f MB', $bytes / 1024.0 / 1024.0);
        }
        if ($abs >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024.0);
        }
        return $bytes . ' B';
    }
}
