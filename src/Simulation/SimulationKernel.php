<?php

declare(strict_types=1);

namespace PHPolygon\Simulation;

use PHPolygon\ECS\World;

/**
 * Per-entity-record simulation kernel a system registers with
 * {@see FarSimulationRunner} for its Far-tier entities - the substrate
 * Phase 4 of the visibility plan builds ahead of Phase 6's threaded
 * FarSimulationSubsystem (this phase runs every kernel inline, on the main
 * thread, via {@see InlineTransport}). Voluntary per system (the plan's
 * decision 5): nothing requires a system that opts into Far-tier entities
 * to also register a kernel - it may instead just skip them outright.
 *
 * export()/import() touch the live World and run exactly once each, on a
 * Near->Far handover and a Far->Near takeback respectively. step() does
 * NOT touch the World - it is a pure function of records and dt, so a
 * future transport can run it off the main thread (or even in a separate
 * process) without synchronising against live ECS state. Any randomness a
 * kernel needs must already be part of the record (e.g. a carried RNG
 * seed/state the kernel advances itself) - step() must never read a global
 * RNG (mt_rand() etc.), or two runs with the same records/dt could diverge.
 *
 * step() MUST treat every record independently: the result for one entity
 * id must not depend on which OTHER ids are present in the same call.
 * {@see FarSimulationRunner::takeback()} relies on this - it flushes a
 * single record's still-owed steps alone, separate from its batch siblings.
 */
interface SimulationKernel
{
    /**
     * Extracts $entityId's current state into a scalar-only record. Called
     * once, on a Near->Far handover.
     *
     * @return array<string, bool|int|float|string>
     */
    public function export(World $world, int $entityId): array;

    /**
     * Advances every given record by $dt. Pure: no World access, no side
     * effects beyond the return value, no global RNG. Must be correct for
     * ANY non-negative $dt, not only {@see FarSimulationRunner::FIXED_DT} -
     * a takeback flush may pass a different, smaller remainder.
     *
     * @param array<int, array<string, bool|int|float|string>> $records entityId => record, ascending id order
     * @return array<int, array<string, bool|int|float|string>> same keys, updated records
     */
    public function step(array $records, float $dt): array;

    /**
     * Writes $entityId's record back into its live components. Called
     * once, on a Far->Near takeback, after every step it was still owed.
     *
     * @param array<string, bool|int|float|string> $record
     */
    public function import(World $world, int $entityId, array $record): void;
}
