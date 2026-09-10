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
 *
 * {@see $ledger} is the CPU-side bookkeeping of the slot ring: where the next
 * spawn batch goes, which slots may still be alive, and the rows and time not
 * yet handed to the GPU.
 *
 * The compute kernels are bound to exactly this state's buffers and never
 * shared: ext-vio keeps every buffer ever bound to a pipeline for the
 * pipeline's lifetime (see {@see GpuParticleBaker}).
 */
final class GpuParticleState
{
    /** Ring bookkeeping: head, spawn batches, conservative live count, pending rows. */
    public readonly GpuParticleLedger $ledger;

    /** Integration + billboard kernel ({@see GpuParticleBaker::SHADER}); created on first use. */
    public ?\VioComputePipeline $stepKernel = null;

    /** Spawn copy kernel reading {@see $rowsBuf}; created on first use. */
    public ?\VioComputePipeline $spawnKernel = null;

    /** Argument record reset kernel; created on first use. */
    public ?\VioComputePipeline $resetKernel = null;

    /** Compacting integration kernel; created on first use. */
    public ?\VioComputePipeline $compactKernel = null;

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
         * Persistent upload buffer for spawn rows (capacity*8 floats), rewritten
         * per spawn. Null where the backend cannot rewrite an upload buffer —
         * every spawn then uploads into a fresh buffer.
         */
        public readonly ?\VioBuffer $rowsBuf = null,
    ) {
        $this->ledger = $ledger ?? new GpuParticleLedger($capacity);
    }
}
