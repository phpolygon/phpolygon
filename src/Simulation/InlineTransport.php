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
 *
 * submit()/collect() (Phase 6b) compute eagerly inside submit() - there is
 * nothing to actually wait for on the calling thread. The one-far-tick
 * latency {@see FarSimulationRunner::tick()} relies on for async parity with
 * {@see ChannelTransport} comes entirely from ITS OWN control flow (it never
 * attempts a collect() in the same tick() call as the submit() that created
 * the token), not from anything this class does to artificially delay a
 * result it already has.
 */
final class InlineTransport implements SimulationTransport
{
    private int $nextToken = 0;

    /** @var array<int, array<int, array<string, bool|int|float|string>>> */
    private array $results = [];

    public function step(SimulationKernel $kernel, array $records, float $dt): array
    {
        ksort($records);
        return $kernel->step($records, $dt);
    }

    public function submit(SimulationKernel $kernel, array $records, int $steps, float $dt): mixed
    {
        ksort($records);
        for ($i = 0; $i < $steps; $i++) {
            $records = $kernel->step($records, $dt);
        }
        $token = $this->nextToken++;
        $this->results[$token] = $records;
        return $token;
    }

    public function collect(mixed $token, bool $wait = false): ?array
    {
        if (!is_int($token) || !isset($this->results[$token])) {
            return null;
        }
        $result = $this->results[$token];
        unset($this->results[$token]);
        return $result;
    }
}
