<?php

declare(strict_types=1);

namespace PHPolygon\Simulation;

/**
 * How a batch of records actually gets stepped - {@see InlineTransport} runs
 * the kernel synchronously on the calling thread (what Phase 4 builds);
 * {@see ChannelTransport} hands the same records to a worker thread instead
 * (Phase 6). {@see FarSimulationRunner} is written against this interface so
 * swapping the transport later needs no change to it or to any registered
 * {@see SimulationKernel}.
 *
 * Phase 6b adds {@see submit()}/{@see collect()}: an async, pipelined
 * alternative to {@see step()} for a kernel registered with
 * `FarSimulationRunner::register(..., async: true)`. {@see step()} itself is
 * unchanged and still the default - async is opt-in per kernel, not a
 * replacement (see {@see FarSimulationRunner}'s class doc for why: making
 * every kernel pay a submit/collect round trip's latency, even one that will
 * only ever run inline, was rejected as the wrong default).
 */
interface SimulationTransport
{
    /**
     * @param array<int, array<string, bool|int|float|string>> $records
     * @return array<int, array<string, bool|int|float|string>>
     */
    public function step(SimulationKernel $kernel, array $records, float $dt): array;

    /**
     * Starts $steps chained applications of $kernel->step() at $dt each,
     * without blocking for the result - {@see collect()} retrieves it. A
     * transport MUST reject a second submit() before the first token's
     * result was collected (implementations throw {@see \LogicException});
     * {@see FarSimulationRunner} itself never does this (one job in flight
     * per kernel, by construction), but a transport is not required to trust
     * every caller to honour that on its own.
     *
     * @param array<int, array<string, bool|int|float|string>> $records
     * @return mixed opaque token for {@see collect()}
     */
    public function submit(SimulationKernel $kernel, array $records, int $steps, float $dt): mixed;

    /**
     * Retrieves a {@see submit()}ted job's result. Non-blocking by default -
     * returns null when it is not ready yet, which is the EXPECTED outcome
     * of a poll that simply has not caught up, not an error. $wait: true
     * blocks until the result is ready (the save/takeback path, where the
     * caller cannot proceed without it) and never returns null - a transport
     * that cannot honour $wait within its own bound throws instead (the same
     * contract {@see step()} already has for a worker that never answers).
     *
     * @return array<int, array<string, bool|int|float|string>>|null
     */
    public function collect(mixed $token, bool $wait = false): ?array;
}
