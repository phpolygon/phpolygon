<?php

declare(strict_types=1);

namespace PHPolygon\Component;

/**
 * How a {@see ParticleEmitter} orients each particle's mesh.
 *
 * Billboard and Stretched turn the mesh's local XY plane towards the camera
 * (local +X = right, +Y = up, +Z = towards the camera), so flat particle
 * meshes are authored in the XY plane facing +Z; closed meshes (a sphere)
 * work in every mode. WorldAligned keeps the mesh in world axes.
 */
enum ParticleRenderMode: string
{
    /** Camera-facing, uniformly scaled by the particle size (the default). */
    case Billboard = 'billboard';

    /**
     * Velocity-aligned streak: local +Y points along the particle's velocity
     * and is lengthened to `size + speed × stretch`, the quad turns about that
     * axis towards the camera, and the streak trails behind the particle
     * (its head stays at the particle position). Rain, sparks, water jets.
     * A particle at rest or moving straight at the camera falls back to a
     * plain billboard.
     */
    case Stretched = 'stretched';

    /**
     * No camera alignment: the mesh keeps world axes, scaled by the particle
     * size — e.g. a flat ring mesh (normal +Y) as an expanding ripple on water.
     */
    case WorldAligned = 'world';

    /** Shader code of the mode (the `mode` param of the particle kernels). */
    public function code(): int
    {
        return match ($this) {
            self::Billboard => 0,
            self::Stretched => 1,
            self::WorldAligned => 2,
        };
    }
}
