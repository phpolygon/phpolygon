<?php

declare(strict_types=1);

namespace PHPolygon\System;

/**
 * CPU-side bookkeeping for one GPU-simulated emitter: a ring of particle slots
 * whose contents live only in GPU memory.
 *
 * Spawns are written to the ring as contiguous batches starting at {@see head()}
 * and the batches are tracked in spawn order (FIFO) with their expiry time. The
 * live count is conservative: a batch is only released once it expired AND
 * every older batch was released before it. Live slots therefore always form
 * the contiguous range of {@see count()} slots in front of the head (pending
 * rows included), so writing at the head never overwrites a living particle —
 * even when the emitter lifetime changes at runtime and a younger batch would
 * expire before an older one.
 *
 * Rows are queued during the simulation update ({@see queue()}) and written to
 * the GPU once per render ({@see takePending()}), together with one integration
 * step over all time simulated since the previous render ({@see takeDt()}). A
 * row spawned part-way through that span must not receive the part of the step
 * that happened before it was spawned; {@see takePending()} rewinds age,
 * velocity and position by exactly that amount, so after the shared GPU step
 * the particle is where the CPU path would have it.
 *
 * Pure PHP, no GPU access — owned by {@see GpuParticleState}.
 */
final class GpuParticleLedger
{
    /**
     * Seconds a batch is kept past its computed expiry before its slots count
     * as free. The GPU accumulates age in float32 while this ledger sums the
     * clock in float64; the slack keeps the ledger on the conservative side of
     * that rounding. It is far below a frame, so the count only differs from a
     * CPU simulation when an expiry lands within the slack after a frame.
     */
    public const EXPIRY_SLACK = 1.0e-3;

    /** Floats per particle row: px, py, pz, vx, vy, vz, age, lifetime. */
    public const ROW_FLOATS = 8;

    /** Released batches kept at the front of the FIFO before it is compacted. */
    private const COMPACT_AFTER = 64;

    private int $head = 0;
    private float $clock = 0.0;
    private float $pendingDt = 0.0;
    private int $live = 0;

    /** @var list<array{0: float, 1: int}> spawn batches [expiresAt, size] in spawn order; entries before $front are released */
    private array $batches = [];
    private int $front = 0;

    /**
     * Batches queued but not yet written to the GPU, oldest first: [time
     * already simulated since the last render when queued, rows]. They are
     * always the newest entries of $batches.
     *
     * @var list<array{0: float, 1: list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}>}>
     */
    private array $pending = [];
    private int $pendingCount = 0;

    public function __construct(
        /** Ring slot count — the emitter's maxParticles. */
        public readonly int $capacity,
        /** Emitter generation the ring belongs to; see {@see isCurrent()}. */
        public readonly int $generation = 0,
    ) {}

    /**
     * True when this ledger still describes an emitter with the given capacity
     * and generation. A cleared emitter bumps its generation, so a stale ring
     * (and the GPU state that owns it) is recreated instead of reused.
     */
    public function isCurrent(int $capacity, int $generation): bool
    {
        return $this->capacity === $capacity && $this->generation === $generation;
    }

    /** Conservative live particle count, pending rows included. */
    public function count(): int
    {
        return $this->live;
    }

    /** Slots a new batch may occupy without touching a living particle. */
    public function room(): int
    {
        return max(0, $this->capacity - $this->live);
    }

    /** Ring slot the next batch is written to. */
    public function head(): int
    {
        return $this->head;
    }

    /** Total simulated time. */
    public function clock(): float
    {
        return $this->clock;
    }

    /** Rows queued but not yet written to the GPU. */
    public function pendingCount(): int
    {
        return $this->pendingCount;
    }

    /** Simulated time not yet integrated on the GPU. */
    public function pendingDt(): float
    {
        return $this->pendingDt;
    }

    /** Advance the clock by one simulation step and release expired batches. */
    public function advance(float $dt): void
    {
        if ($dt <= 0.0) {
            return;
        }
        $this->clock += $dt;
        $this->pendingDt += $dt;

        $end = count($this->batches);
        while ($this->front < $end) {
            [$expiresAt, $size] = $this->batches[$this->front];
            if ($this->clock < $expiresAt + self::EXPIRY_SLACK) {
                break;
            }
            // The oldest batch is still pending (every older batch is gone):
            // its rows would be dead on arrival, so they are never written and
            // the head does not move for them.
            if ($this->front >= $end - count($this->pending)) {
                array_shift($this->pending);
                $this->pendingCount -= $size;
            }
            $this->live -= $size;
            $this->front++;
        }

        if ($this->front >= self::COMPACT_AFTER) {
            $this->batches = array_slice($this->batches, $this->front);
            $this->front = 0;
        }
    }

    /**
     * Queue freshly spawned rows (age 0) for the next GPU write. Rows beyond
     * {@see room()} are dropped. Returns the number of rows accepted.
     *
     * @param list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> $rows
     */
    public function queue(array $rows, float $lifetime): int
    {
        $room = $this->room();
        if (count($rows) > $room) {
            $rows = array_slice($rows, 0, $room);
        }
        $size = count($rows);
        if ($size === 0) {
            return 0;
        }
        $this->batches[] = [$this->clock + $lifetime, $size];
        $this->pending[] = [$this->pendingDt, $rows];
        $this->live += $size;
        $this->pendingCount += $size;
        return $size;
    }

    /**
     * Register rows that are already in the ring at slots 0..n-1 (a freshly
     * seeded GPU state). Each row expires after its remaining lifetime; rows
     * that are already dead still occupy their slot until released in order.
     * Only valid on an empty ledger.
     *
     * @param list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> $rows
     */
    public function seed(array $rows): void
    {
        if ($this->live !== 0 || $this->batches !== []) {
            throw new \LogicException('GpuParticleLedger::seed() requires an empty ledger');
        }
        $size = min(count($rows), $this->capacity);
        for ($i = 0; $i < $size; $i++) {
            $this->batches[] = [$this->clock + ($rows[$i][7] - $rows[$i][6]), 1];
        }
        $this->live = $size;
        $this->head = $size % max(1, $this->capacity);
    }

    /**
     * Pack every pending row as raw f32 bytes (8 floats per row) for
     * {@see GpuParticleBaker::inject()} and forget them. Each row is rewound by
     * the time that had already been simulated when it was queued, against the
     * given gravity, so the next GPU step over {@see pendingDt()} integrates it
     * only over the time it actually existed. Returns '' when nothing is pending.
     */
    public function takePending(float $gx, float $gy, float $gz): string
    {
        if ($this->pendingCount === 0) {
            return '';
        }
        $span = $this->pendingDt;
        $flat = [];
        foreach ($this->pending as [$skip, $rows]) {
            $lived = $span - $skip;
            $lgx = $gx * $lived; $lgy = $gy * $lived; $lgz = $gz * $lived;
            $sgx = $gx * $skip;  $sgy = $gy * $skip;  $sgz = $gz * $skip;
            foreach ($rows as $r) {
                // Velocity the step will end on, and the start state that one
                // semi-implicit step of $span turns into "integrated over $lived".
                $vx = $r[3] + $lgx;
                $vy = $r[4] + $lgy;
                $vz = $r[5] + $lgz;
                $flat[] = $r[0] - $vx * $skip;
                $flat[] = $r[1] - $vy * $skip;
                $flat[] = $r[2] - $vz * $skip;
                $flat[] = $r[3] - $sgx;
                $flat[] = $r[4] - $sgy;
                $flat[] = $r[5] - $sgz;
                $flat[] = $r[6] - $skip;
                $flat[] = $r[7];
            }
        }
        $this->pending = [];
        $this->pendingCount = 0;
        return pack('f*', ...$flat);
    }

    /** Move the head past $n freshly written slots (wrapping around the ring). */
    public function advanceHead(int $n): void
    {
        if ($this->capacity <= 0 || $n <= 0) {
            return;
        }
        $this->head = ($this->head + $n) % $this->capacity;
    }

    /** Simulated time since the last GPU step; resets it to zero. */
    public function takeDt(): float
    {
        $dt = $this->pendingDt;
        $this->pendingDt = 0.0;
        return $dt;
    }
}
