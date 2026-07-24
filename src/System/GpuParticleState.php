<?php

declare(strict_types=1);

namespace PHPolygon\System;

/**
 * GPU-resident state for one emitter's particle simulation, owned by
 * {@see GpuParticleBaker}.
 *
 * Two storage buffers live on the GPU across frames so the per-frame cost is
 * just dispatch (+ readback), never a full state re-upload:
 *   - {@see $stateBuf}: capacity * 8 floats, read-write. One row per slot:
 *     [px, py, pz, vx, vy, vz, age, lifetime]. The compute shader integrates
 *     it in place.
 *   - {@see $outBuf}: capacity * 16 floats, write-only. The finished
 *     column-major instance matrices, read back and forwarded verbatim to
 *     {@see \PHPolygon\Rendering\Command\DrawMeshInstanced::packed()}.
 *
 * PHASE 1 (this cut) carries no CPU-side slot ledger: the state is seeded once
 * and stepped, which is exactly what {@see GpuParticleBaker::step()} and the
 * benchmark exercise. Live spawning into dead slots (the CPU free-slot cursor
 * of the briefing's A.3) is Phase 2 work and will hang additional fields here.
 */
final class GpuParticleState
{
    public function __construct(
        /** Slot capacity — equals the emitter's maxParticles. */
        public readonly int $capacity,
        /** RW state SSBO, capacity*8 floats. */
        public readonly \VioBuffer $stateBuf,
        /** Write-only output matrix SSBO, capacity*16 floats. */
        public readonly \VioBuffer $outBuf,
    ) {}
}
