<?php

declare(strict_types=1);

namespace PHPolygon\Simulation;

/**
 * Runs a {@see SimulationKernel}'s step() synchronously on the calling
 * (main) thread - the fallback the plan asks to build first, before
 * Phase 6's threaded transport exists. Sorts records into ascending
 * entity-id order before calling step() - the plan's determinism
 * requirement ("Zusammenführen in Reihenfolge (Tick, Entity-Id,
 * Folgenummer)"); with everything running inline there is no concurrent
 * merge to order, only this one deterministic input order.
 */
final class InlineTransport implements SimulationTransport
{
    public function step(SimulationKernel $kernel, array $records, float $dt): array
    {
        ksort($records);
        return $kernel->step($records, $dt);
    }
}
