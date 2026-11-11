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
 * Failure contract: if threadEntry() (under {@see ThreadScheduler}) or
 * compute() (under {@see NullThreadScheduler}) throws or returns before a
 * clean shutdown, the scheduler detects it — via the kept
 * {@see \parallel\Future} on the threaded path, via a caught exception on the
 * synchronous one — logs it once, and marks the subsystem dead for the rest
 * of that boot(): it is skipped by sendAll()/pollAll() afterwards rather than
 * hanging the game loop. There is no automatic restart; both schedulers carry
 * on running every other subsystem.
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
