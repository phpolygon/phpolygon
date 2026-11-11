<?php

declare(strict_types=1);

namespace PHPolygon\Simulation;

use PHPolygon\ECS\World;

/**
 * Drives every registered {@see SimulationKernel} at a fixed 10 Hz clock
 * (the plan's decision 7), inline on the main thread via
 * {@see InlineTransport} by default (Phase 6 swaps in a threaded transport
 * later - this class does not change then, see {@see SimulationTransport}).
 *
 * Not wired to {@see \PHPolygon\Activity\ActivitySystem} automatically -
 * Fern-Simulation is voluntary per system (the plan's decision 5). A system
 * that wants a far core registers its own kernel here and calls
 * {@see handover()}/{@see takeback()} itself when ITS entities cross the
 * Near<->Far boundary (e.g. from an {@see \PHPolygon\Activity\ActivityService::addObserver()}
 * callback it adds). This class only manages the records and the clock.
 *
 * Determinism (the plan's decision 6 - same handover/takeback SEQUENCE
 * yields the same imported records, not bit-identical with real time):
 * $accumulator only ever grows by the simulated dt {@see tick()} is given
 * and shrinks by exactly {@see FIXED_DT} per applied step, so at any instant
 * (including the moment of a takeback) `floor($accumulator / FIXED_DT)` is
 * the exact count of FIXED_DT-sized steps a record is owed - independent of
 * how tick() calls were chunked or how many of them {@see MAX_STEPS_PER_TICK}
 * deferred. {@see takeback()} reads that count to flush a record fully
 * caught up before import(), WITHOUT decrementing the shared accumulator
 * (the same owed time is still applied to the record's batch siblings on a
 * later tick() call - nothing is double-counted or dropped for them).
 *
 * Async mode (Phase 6b, {@see register()}'s $async flag, opt-in per
 * kernel): drives the SAME transport through submit()/collect() instead of
 * the blocking step() - one job in flight per kernel, submitted at tick N
 * and only ever collected (non-blocking) from tick N+1 onward, never in the
 * same tick() call that submitted it. That one-far-tick pipeline latency is
 * enforced HERE, structurally, by never attempting a same-call collect() -
 * not by the transport ({@see InlineTransport}'s submit() computes eagerly,
 * same as always, and would happily return its result the instant it is
 * asked; it is simply never asked that soon). With the SAME kernel and SAME
 * tick() calls, async mode is therefore consistently one step behind where
 * sync mode would be at the same point - a deliberate, measured latency,
 * not a bug, and the reason async is opt-in rather than the default: a
 * kernel that will only ever run on {@see InlineTransport} has no reason to
 * pay it.
 *
 * Kept deliberately simple over a "real" multi-deep pipeline: at most one
 * job is ever in flight per kernel. A tick() call behind on its clock
 * batches up to MAX_STEPS_PER_TICK chained steps into that one job (the
 * backlog still drains at a bounded rate per call, same spirit as sync
 * mode's own cap), rather than queueing several jobs at once. Queueing more
 * than one job concurrently would need per-job ordering/merge logic this
 * phase did not build - a conservative choice, not a limit any real
 * workload has been found to need.
 */
final class FarSimulationRunner
{
    public const float FIXED_HZ = 10.0;
    public const float FIXED_DT = 1.0 / self::FIXED_HZ;

    /**
     * Design value, not measured: whole FIXED_DT steps applied to a
     * kernel's batch per tick() call. A real stall (a slow frame, or a
     * debugger pause) accumulates owed time instead of bursting through it
     * in one call - the remainder simply stays in $accumulator for the next
     * tick() call, never dropped.
     */
    private const int MAX_STEPS_PER_TICK = 4;

    /**
     * Tolerance below one FIXED_DT, used by both tick()'s loop and
     * takeback()'s flush with the SAME comparison. Repeated float
     * subtraction of FIXED_DT (0.1 s - not exactly representable in binary
     * floating point) can leave $accumulator a hair below an exact
     * multiple, e.g. 0.09999999999999998 instead of 0.1; without this
     * tolerance a step tick() would still have taken could be missed by
     * takeback()'s flush (or vice versa), making the result depend on
     * which one happened to reach that instant first - a real bug this
     * class's own determinism test caught.
     */
    private const float EPSILON = self::FIXED_DT * 1e-9;

    /**
     * @var array<string, array{kernel: SimulationKernel, transport: SimulationTransport, async: bool}>
     */
    private array $kernels = [];

    /** @var array<string, float> kernel name => accumulated sim-time not yet applied as a full step */
    private array $accumulator = [];

    /**
     * @var array<string, array<int, array<string, bool|int|float|string>>>
     *      kernel name => entityId => record, currently handed over to Far
     */
    private array $records = [];

    /**
     * Async mode only: the token of the one job currently in flight for a
     * kernel, or null if none - see the class doc's "at most one job in
     * flight per kernel".
     *
     * @var array<string, mixed>
     */
    private array $pending = [];

    /** @var array<string, int> Async mode only: how many chained FIXED_DT steps $pending[$name]'s job covers. */
    private array $pendingSteps = [];

    /**
     * @param bool $async Opt-in per kernel (Phase 6b) - drives $transport
     *     through submit()/collect() instead of step(), see the class doc.
     *     Leave false (default) for a kernel that should keep today's
     *     zero-added-latency behaviour, including every kernel registered
     *     before Phase 6b existed.
     */
    public function register(string $name, SimulationKernel $kernel, ?SimulationTransport $transport = null, bool $async = false): void
    {
        $this->kernels[$name] = ['kernel' => $kernel, 'transport' => $transport ?? new InlineTransport(), 'async' => $async];
        $this->accumulator[$name] ??= 0.0;
        $this->records[$name] ??= [];
        $this->pending[$name] ??= null;
        $this->pendingSteps[$name] ??= 0;
    }

    /** Near->Far: exports $entityId's current state and starts stepping it at the fixed rate. */
    public function handover(string $name, World $world, int $entityId): void
    {
        $entry = $this->kernels[$name] ?? null;
        if ($entry === null) {
            return;
        }
        $this->records[$name][$entityId] = $entry['kernel']->export($world, $entityId);
    }

    /**
     * Far->Near: applies every FIXED_DT step this record is still owed
     * (see the class docblock on why), imports the caught-up record, then
     * drops it from the batch. A no-op if $entityId was never handed over.
     *
     * Async mode: first blocking-drains this kernel's one in-flight job (if
     * any) via {@see drainPending()} - a job submitted before this call may
     * cover $entityId too, and leaving it unresolved while $entityId is
     * removed from $this->records here would risk its eventual result
     * trying to update a record that no longer exists (drainPending()'s
     * merge already guards against that - see its own doc - but draining
     * now, rather than leaving it to a later tick(), is what lets a game
     * read $entityId's live component values back immediately after this
     * call returns instead of one tick later).
     */
    public function takeback(string $name, World $world, int $entityId): void
    {
        $entry = $this->kernels[$name] ?? null;
        if ($entry === null || !isset($this->records[$name][$entityId])) {
            return;
        }
        $this->drainPending($name);
        $owed = $this->accumulator[$name] ?? 0.0;
        while ($owed >= self::FIXED_DT - self::EPSILON) {
            $single = $entry['transport']->step($entry['kernel'], [$entityId => $this->records[$name][$entityId]], self::FIXED_DT);
            $this->records[$name][$entityId] = $single[$entityId];
            $owed -= self::FIXED_DT;
        }
        $entry['kernel']->import($world, $entityId, $this->records[$name][$entityId]);
        unset($this->records[$name][$entityId]);
    }

    /**
     * Flushes and imports EVERY currently handed-over record, across every
     * registered kernel - the entry point for a save (Phase 6's "Snapshot
     * beim Speichern"): far-simulation state must reach the save file even
     * while a record sits mid-flight in a worker thread. Works unchanged
     * with {@see ChannelTransport} because {@see takeback()}'s flush already
     * calls the kernel's TRANSPORT (not the kernel directly) and
     * ChannelTransport::step() itself blocks until the worker answers - the
     * same synchronous contract {@see InlineTransport} has always had. In
     * async mode, {@see takeback()}'s own {@see drainPending()} call covers
     * the "job mid-flight at save time" case the same way.
     */
    public function takebackAll(World $world): void
    {
        foreach (array_keys($this->records) as $name) {
            foreach (array_keys($this->records[$name]) as $entityId) {
                $this->takeback($name, $world, $entityId);
            }
        }
    }

    /**
     * Accumulates $dt (SIMULATED time - pass the same dt your own update()
     * tick ran with, not a wall-clock reading) for every registered kernel
     * and applies as many FIXED_DT steps to its current record batch as
     * have accumulated, capped at MAX_STEPS_PER_TICK per call. A kernel
     * with no records currently handed over still advances its clock (so a
     * later handover does not inherit a burst of "owed" time from while it
     * had nothing to step).
     */
    public function tick(float $dt): void
    {
        foreach ($this->kernels as $name => $entry) {
            $this->accumulator[$name] += $dt;
            if ($entry['async']) {
                $this->tickAsync($name, $entry);
                continue;
            }
            $steps = 0;
            while ($this->accumulator[$name] >= self::FIXED_DT - self::EPSILON && $steps < self::MAX_STEPS_PER_TICK) {
                if ($this->records[$name] !== []) {
                    $this->records[$name] = $entry['transport']->step($entry['kernel'], $this->records[$name], self::FIXED_DT);
                }
                $this->accumulator[$name] -= self::FIXED_DT;
                $steps++;
            }
        }
    }

    /**
     * Async mode's half of {@see tick()}: first tries to (non-blockingly)
     * collect this kernel's one in-flight job, if any; then, if there is
     * now no job in flight and time is owed, submits a new one covering up
     * to MAX_STEPS_PER_TICK chained steps. Both can happen in the SAME call
     * (collect an old job, submit a new one) - that does not shorten the
     * one-tick latency, because the newly submitted job's own token is not
     * even looked at until a LATER tick() call reaches the collect attempt
     * above.
     *
     * @param array{kernel: SimulationKernel, transport: SimulationTransport, async: bool} $entry
     */
    private function tickAsync(string $name, array $entry): void
    {
        if ($this->pending[$name] !== null) {
            $result = $entry['transport']->collect($this->pending[$name], wait: false);
            if ($result !== null) {
                $this->mergeResult($name, $result);
                $this->accumulator[$name] -= $this->pendingSteps[$name] * self::FIXED_DT;
                $this->pending[$name] = null;
                $this->pendingSteps[$name] = 0;
            }
            // Not ready: nothing more to do this call - keep accumulating,
            // per the plan's "nicht neu abschicken, weiter akkumulieren".
        }

        if ($this->pending[$name] !== null) {
            return;
        }
        $owedSteps = (int) floor(($this->accumulator[$name] + self::EPSILON) / self::FIXED_DT);
        if ($owedSteps <= 0) {
            return;
        }
        $steps = min($owedSteps, self::MAX_STEPS_PER_TICK);
        if ($this->records[$name] === []) {
            // Nothing to simulate - advance the clock for free, same
            // per-call bound as the stepped case (see tick()'s sync branch).
            $this->accumulator[$name] -= $steps * self::FIXED_DT;
            return;
        }
        $this->pending[$name] = $entry['transport']->submit($entry['kernel'], $this->records[$name], $steps, self::FIXED_DT);
        $this->pendingSteps[$name] = $steps;
    }

    /**
     * Async mode only: blocking-drains $name's one in-flight job, if any -
     * used by {@see takeback()} (and therefore {@see takebackAll()}) before
     * either reads $this->records, so a save or an individual Far->Near
     * crossing never leaves an unresolved job behind.
     */
    private function drainPending(string $name): void
    {
        if (($this->pending[$name] ?? null) === null) {
            return;
        }
        $entry = $this->kernels[$name];
        $result = $entry['transport']->collect($this->pending[$name], wait: true);
        if ($result === null) {
            // Contract violation, not a normal outcome: collect(wait: true)
            // must either return the result or throw (see SimulationTransport's
            // own doc) - a well-behaved transport never reaches this.
            throw new \RuntimeException("FarSimulationRunner: transport for '{$name}' returned null from a blocking collect().");
        }
        $this->mergeResult($name, $result);
        $this->accumulator[$name] -= $this->pendingSteps[$name] * self::FIXED_DT;
        $this->pending[$name] = null;
        $this->pendingSteps[$name] = 0;
    }

    /**
     * Applies a collected job's result into $this->records[$name], but only
     * for ids STILL present there - defensive, not routinely exercised: with
     * THIS class's own call discipline ({@see takeback()} always drains the
     * pending job first, before it removes anything, so the id it is about
     * to remove can never be part of an UNRESOLVED job by the time it is
     * removed) there is currently no sequence of public calls that leaves a
     * collected job's result pointing at an id already gone from
     * $this->records. Kept anyway in case a future change (or a caller
     * driving a transport directly, bypassing this class) ever calls
     * collect() for a job whose ids were not all kept around for it -
     * dropping a stale id silently is the safe failure mode, not resurrecting
     * a record this class already considers gone.
     *
     * @param array<int, array<string, bool|int|float|string>> $result
     */
    private function mergeResult(string $name, array $result): void
    {
        foreach ($result as $id => $record) {
            if (array_key_exists($id, $this->records[$name])) {
                $this->records[$name][$id] = $record;
            }
        }
    }

    /**
     * The live record for one handed-over entity, or null if it was never
     * handed over (or already taken back) - for tests and diagnostics.
     *
     * @return array<string, bool|int|float|string>|null
     */
    public function recordOf(string $name, int $entityId): ?array
    {
        return $this->records[$name][$entityId] ?? null;
    }

    public function isHandedOver(string $name, int $entityId): bool
    {
        return isset($this->records[$name][$entityId]);
    }
}
