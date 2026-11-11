<?php

declare(strict_types=1);

namespace PHPolygon\Simulation;

/**
 * How a batch of records actually gets stepped - {@see InlineTransport} runs
 * the kernel synchronously on the calling thread (what Phase 4 builds);
 * Phase 6 adds a `ChannelTransport` that hands the same records to a worker
 * thread instead. {@see FarSimulationRunner} is written against this
 * interface so swapping the transport later needs no change to it or to any
 * registered {@see SimulationKernel}.
 */
interface SimulationTransport
{
    /**
     * @param array<int, array<string, bool|int|float|string>> $records
     * @return array<int, array<string, bool|int|float|string>>
     */
    public function step(SimulationKernel $kernel, array $records, float $dt): array;
}
