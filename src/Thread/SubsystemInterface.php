<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

use PHPolygon\ECS\World;

/**
 * Interface for subsystems that can run in a worker thread.
 *
 * Data flow per frame:
 *   1. Main thread calls prepareInput() to extract serializable state from World
 *   2. Scheduler sends input to worker thread via Channel
 *   3. Worker thread runs computation (threadEntry) and sends deltas back
 *   4. Main thread calls applyDeltas() to write results back to World
 *
 * Failure contract — {@see ThreadScheduler} only (its worker threads are
 * independent of the main thread; {@see NullThreadScheduler} has none, so a
 * throwing compute() there behaves exactly as before this contract existed:
 * the exception propagates out of the game loop): if threadEntry() throws or
 * returns before a clean shutdown, {@see ThreadScheduler::pollAll()} detects
 * it via the kept {@see \parallel\Future}, logs it once, and marks the
 * subsystem dead for the rest of that boot() — skipped by sendAll()/pollAll()
 * afterwards rather than hanging the game loop. There is no automatic
 * restart; the scheduler carries on running every other subsystem.
 *
 * Side channel: the array returned by compute() (and sent back via
 * threadEntry()'s out channel) may carry two reserved keys that never reach
 * applyDeltas() — both schedulers strip them first:
 *   - `__log`: a list of `['text' => string, 'context' => array (optional),
 *     'level' => 'info'|'warn'|'error' (optional, default 'info')]` entries,
 *     written through {@see \PHPolygon\Log\Log} as `LogCategory::System`,
 *     with `subsystem` added to each entry's context.
 *   - `__timings`: a map of section name to elapsed nanoseconds (int), each
 *     recorded via {@see \PHPolygon\Runtime\PerfProfiler::record()} as
 *     `thread.<name>.<section>` — the thread's own measured work, as opposed
 *     to `thread.<name>.wait`, which the scheduler records itself and which
 *     only measures how long the main thread blocked waiting for it.
 * Both keys are optional; a subsystem that never sets them is unaffected, and
 * existing subsystems (AISubsystem, PhysicsSubsystem, ...) need no change.
 */
interface SubsystemInterface
{
    /**
     * Extract serializable state from the World for the worker thread.
     * Runs on the main thread. Must return only arrays and primitives.
     *
     * @return array<string, mixed>
     */
    public function prepareInput(World $world, float $dt): array;

    /**
     * Apply received deltas back to the World.
     * Runs on the main thread — the only code that writes to World.
     *
     * @param array<string, mixed> $deltas
     */
    public function applyDeltas(World $world, array $deltas): void;

    /**
     * The worker thread entry point. Called inside the parallel Runtime.
     *
     * Must be static because Runtime::run() closures cannot capture objects.
     * Uses named Channels: Channel::open("{name}_in") and Channel::open("{name}_out").
     *
     * Loops on in->recv() until null (shutdown signal), sends deltas via out->send().
     */
    public static function threadEntry(string $channelPrefix): void;

    /**
     * Compute deltas from input synchronously (for NullThreadScheduler fallback).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function compute(array $input): array;
}
