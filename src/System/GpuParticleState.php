<?php

declare(strict_types=1);

namespace PHPolygon\System;

/**
 * GPU-resident state for one emitter's particle simulation, owned by
 * {@see GpuParticleBaker}.
 *
 * Storage buffers live on the GPU across frames so the per-frame cost is just
 * dispatch (+ readback on the packed path), never a full state re-upload:
 *   - {@see $stateBuf}: capacity * 8 floats, read-write. One row per slot:
 *     [px, py, pz, vx, vy, vz, age, lifetime]. The compute shader integrates
 *     it in place; {@see GpuParticleBaker::inject()} writes new rows into it.
 *   - {@see $outBuf}: capacity * 16 floats, write-only. The finished
 *     column-major instance matrices — read back for
 *     {@see \PHPolygon\Rendering\Command\DrawMeshInstanced::packed()}, or bound
 *     directly as the instance source of an indirect draw.
 *   - {@see $rowsBufs}: the spawn upload buffers, used in rotation.
 *
 * {@see $ledger} is the CPU-side bookkeeping of the slot ring: where the next
 * spawn batch goes, which slots may still be alive, and the rows and time not
 * yet handed to the GPU.
 *
 * The compute kernels are shared by every state of a context; each dispatch
 * binds this state's buffers (see {@see GpuParticleBaker}).
 */
final class GpuParticleState
{
    /**
     * Upload buffers a CPU write rotates through. A dispatch recorded into a
     * frame reads its upload buffer when that frame runs on the GPU, so the
     * buffer must not be rewritten until then: at most two further frames are
     * in flight, and a frame uploads to one state at most twice (seed + spawn).
     */
    public const UPLOAD_ROTATION = 3;

    /** Ring bookkeeping: head, spawn batches, conservative live count, pending rows. */
    public readonly GpuParticleLedger $ledger;

    /** Index into {@see $rowsBufs} of the next spawn upload. */
    private int $nextRows = 0;

    public function __construct(
        /** Slot capacity — equals the emitter's maxParticles. */
        public readonly int $capacity,
        /** RW state SSBO, capacity*8 floats. */
        public readonly \VioBuffer $stateBuf,
        /** Write-only output matrix SSBO, capacity*16 floats. */
        public readonly \VioBuffer $outBuf,
        /**
         * Indirect draw arguments (php-vio >= 2.17, VIO_FEATURE_INDIRECT_DRAW):
         * one {indexCount, instanceCount, firstIndex, baseVertex, firstInstance}
         * record. {@see GpuParticleBaker::stepIndirect()} compacts the live slots
         * to the front of $outBuf and counts them into instanceCount, so the
         * draw issues exactly the live particles without a readback. Null when
         * the state was created without a mesh index count or the backend
         * cannot draw indirectly.
         */
        public readonly ?\VioBuffer $argsBuf = null,
        /** Index count of the mesh the particles are drawn with; 0 when unknown. */
        public readonly int $indexCount = 0,
        ?GpuParticleLedger $ledger = null,
        /**
         * Spawn upload buffers (capacity*8 floats each), rewritten in rotation
         * ({@see UPLOAD_ROTATION}). A state without any cannot spawn.
         *
         * @var list<\VioBuffer>
         */
        public readonly array $rowsBufs = [],
    ) {
        $this->ledger = $ledger ?? new GpuParticleLedger($capacity);
    }

    /** The upload buffer for the next spawn batch, or null when the state has none. */
    public function nextRowsBuffer(): ?\VioBuffer
    {
        if ($this->rowsBufs === []) {
            return null;
        }
        $buffer = $this->rowsBufs[$this->nextRows % count($this->rowsBufs)];
        $this->nextRows = ($this->nextRows + 1) % count($this->rowsBufs);
        return $buffer;
    }
}
