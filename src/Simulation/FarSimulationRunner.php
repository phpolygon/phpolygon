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
     * @var array<string, array{kernel: SimulationKernel, transport: SimulationTransport}>
     */
    private array $kernels = [];

    /** @var array<string, float> kernel name => accumulated sim-time not yet applied as a full step */
    private array $accumulator = [];

    /**
     * @var array<string, array<int, array<string, bool|int|float|string>>>
     *      kernel name => entityId => record, currently handed over to Far
     */
    private array $records = [];

    public function register(string $name, SimulationKernel $kernel, ?SimulationTransport $transport = null): void
    {
        $this->kernels[$name] = ['kernel' => $kernel, 'transport' => $transport ?? new InlineTransport()];
        $this->accumulator[$name] ??= 0.0;
        $this->records[$name] ??= [];
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
     */
    public function takeback(string $name, World $world, int $entityId): void
    {
        $entry = $this->kernels[$name] ?? null;
        if ($entry === null || !isset($this->records[$name][$entityId])) {
            return;
        }
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
