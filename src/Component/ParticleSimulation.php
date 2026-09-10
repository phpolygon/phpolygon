<?php

declare(strict_types=1);

namespace PHPolygon\Component;

/**
 * Where a {@see ParticleEmitter} is simulated.
 *
 * GPU-simulated emitters keep their particles in GPU memory only: the
 * emitter's `$particles` array stays empty and {@see ParticleEmitter::count()}
 * reports the conservative live count of the GPU ring. Choose {@see self::Cpu}
 * when game code needs to read particle positions.
 */
enum ParticleSimulation: string
{
    /**
     * Simulate on the GPU (compute integration + GPU-driven indirect draw) when
     * the renderer supports it and the particle mesh is indexed; otherwise on
     * the CPU.
     */
    case Auto = 'auto';

    /** Always simulate on the CPU, even where the GPU path is available. */
    case Cpu = 'cpu';
}
