<?php

declare(strict_types=1);

namespace PHPolygon\Runtime\Warmup;

/**
 * A piece of start-up work that only makes the game faster later: baking a cache,
 * opening audio, warming a pipeline. The game registers tasks; {@see WarmupRunner}
 * runs them behind the splash, a slice per frame.
 *
 * A task is a pure acceleration. The game has to work unchanged when it never
 * runs (opt-out, an exception, a window closed halfway), so a task must not be
 * the only place something is initialised. That is what makes failure isolation
 * and the opt-out safe.
 *
 * `run()` may return a generator to work in slices: every `yield` is a point where
 * the runner may hand the frame back. The yielded value, when it is a float, is
 * the fraction of this task that is done (0..1); `yield;` alone says nothing.
 * A task that returns null is atomic: the runner cannot interrupt it, so keep it
 * short or make it a generator.
 *
 * Names, labels and values are the game's: the engine treats ids and label keys as
 * opaque strings.
 */
interface WarmupTask
{
    /** Unique id; what {@see dependsOn()} of other tasks names. */
    public function id(): string;

    /**
     * What the screen says while the task runs: a key the game resolves (a
     * translation key, say), or null for the engine's default text.
     */
    public function labelKey(): ?string;

    /** Share of the progress bar, > 0. Only relative to the other tasks. */
    public function weight(): float;

    /**
     * Ids of tasks that have to finish well first. A task whose dependency fails,
     * is skipped, is unknown or is part of a cycle is skipped, never fatal.
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    /**
     * Do the work. Null = done within this call; a generator = work in slices.
     *
     * @return \Generator<int, mixed, mixed, mixed>|null
     */
    public function run(): ?\Generator;
}
