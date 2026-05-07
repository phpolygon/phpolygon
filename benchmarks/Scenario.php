<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks;

use PHPolygon\Engine;

/**
 * A reproducible engine workload. Implementations build up world state in
 * setUp() and may optionally do per-frame work in tickFrame() beyond what
 * the ECS does on its own. Scenarios MUST seed any randomness so two runs
 * of the same scenario produce comparable numbers.
 */
interface Scenario
{
    public function name(): string;

    /**
     * Configure the engine before the run loop starts. Implementations
     * register systems, spawn entities, and seed any pseudo-random state.
     */
    public function setUp(Engine $engine): void;

    /**
     * Optional per-frame hook. Most scenarios do not need this — the ECS
     * loop runs automatically. Use this for scripted input or to advance
     * a deterministic state machine. $frame is 0-based across the full
     * run (warmup + measure).
     */
    public function tickFrame(Engine $engine, int $frame, float $dt): void;
}
