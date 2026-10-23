<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\System\GpuParticleLedger;

/**
 * Spawns and integrates a swarm of point-particles in world space.
 *
 * Storage layout: nested `array[8]` per particle -- one row per
 * particle: [px, py, pz, vx, vy, vz, age, lifetime]. PHP's nested
 * array path beats parallel-flat-arrays at this size class
 * (benchmarked at 256 and 4096 particles, see
 * benchmarks/micro/System/ParticleStorageBench.php). The render side
 * sidesteps Mat4 allocations by writing directly into a flat
 * float[N*16] buffer that {@see DrawMeshInstanced::flat()} consumes
 * without per-instance object overhead - that is where the measured
 * 1.7-4.5x render speed-up comes from.
 *
 * Capacity is bounded by `$maxParticles`; the system grows / shrinks
 * the live array up to that cap.
 *
 * Per-particle colour (start->end interpolation) is recorded but not
 * yet wired into the render pipeline - that needs a per-instance
 * colour attribute stream which is out of scope for the first cut.
 */
#[Serializable]
#[Category('Rendering')]
class ParticleEmitter extends AbstractComponent
{
    /** Mesh id used for each particle quad - must be a unit-quad mesh. */
    #[Property]
    public string $meshId;

    /** Material id for the particle quad. */
    #[Property]
    public string $materialId;

    /** Particles spawned per second. */
    #[Property]
    public float $rate;

    /** Lifetime in seconds. */
    #[Property]
    public float $lifetime;

    /** Initial velocity (m/s). */
    #[Property]
    public Vec3 $velocity;

    /** Random velocity jitter applied per particle (per-axis range). */
    #[Property]
    public Vec3 $velocityJitter;

    /** Constant world acceleration (gravity). */
    #[Property]
    public Vec3 $gravity;

    /** Particle quad size at birth (world-units). */
    #[Property]
    public float $startSize;

    /** Particle quad size at death. */
    #[Property]
    public float $endSize;

    /** Colour at birth. */
    #[Property]
    public Color $startColor;

    /** Colour at death. */
    #[Property]
    public Color $endColor;

    /** Hard cap on simultaneously alive particles. */
    #[Property]
    public int $maxParticles;

    /**
     * Where the particles are simulated. Auto runs on the GPU where the
     * renderer supports it — the particles then live in GPU memory only and
     * `$particles` stays empty. Cpu keeps the CPU simulation, e.g. when game
     * code reads particle positions.
     */
    #[Property]
    public ParticleSimulation $simulation = ParticleSimulation::Auto;

    /** How each particle's mesh is oriented ({@see ParticleRenderMode}). */
    #[Property]
    public ParticleRenderMode $renderMode = ParticleRenderMode::Billboard;

    /**
     * {@see ParticleRenderMode::Stretched} only: seconds of travel one streak
     * covers – its length is `size + speed × stretch`. 0 = no stretch.
     */
    #[Property]
    public float $stretch = 0.0;

    /**
     * Extra horizontal launch speed (m/s) outward in a random direction about
     * the world Y axis – rings, umbrellas, crowns. Added to `$velocity` and
     * its jitter. 0 = off.
     */
    #[Property]
    public float $radialSpeed = 0.0;

    /**
     * With radial shapes: > 0 snaps the random direction to this many evenly
     * spaced spokes (the first along +X) – separate arcs of a fountain, the
     * rays of a starburst. 0 = any direction.
     */
    #[Property]
    public int $radialSpokes = 0;

    /**
     * Particles start on a horizontal circle of this radius around the
     * emitter (m), in the same direction as `$radialSpeed`. 0 = at the emitter.
     */
    #[Property]
    public float $spawnRadius = 0.0;

    /** Random ± spread of `$spawnRadius` (m) – turns the circle into a band. */
    #[Property]
    public float $spawnRadiusJitter = 0.0;

    /**
     * Spawn only while the camera is within this distance of the emitter (m);
     * further away live particles run out and nothing new is born. 0 = always.
     * Measured against the camera of the last rendered frame; before any frame
     * was rendered the emitter spawns.
     */
    #[Property]
    public float $activeDistance = 0.0;

    /**
     * Live particles. Each entry: [px, py, pz, vx, vy, vz, age, lifetime].
     * Nested rather than parallel because PHP arrays are fast at this
     * shape - the system reads with one hash-table lookup per particle
     * and writes the next slot via array_push semantics, which beat
     * eight parallel-array index assignments in benchmarks.
     *
     * @var array<int, array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>
     */
    public array $particles = [];

    /** Spawn-rate accumulator carried frame-to-frame for fractional rates. */
    public float $spawnAccumulator = 0.0;

    /** Bumped by {@see clear()}; a GPU simulation of an older generation is discarded. */
    public int $generation = 0;

    /**
     * Slot ledger of the GPU simulation currently driving this emitter, null
     * while it is simulated on the CPU. Maintained by the particle system.
     */
    public ?GpuParticleLedger $gpuLedger = null;

    public function __construct(
        string $meshId = 'particle_quad',
        string $materialId = 'particle_default',
        float $rate = 30.0,
        float $lifetime = 1.5,
        ?Vec3 $velocity = null,
        ?Vec3 $velocityJitter = null,
        ?Vec3 $gravity = null,
        float $startSize = 0.2,
        float $endSize = 0.0,
        ?Color $startColor = null,
        ?Color $endColor = null,
        int $maxParticles = 256,
        ParticleSimulation $simulation = ParticleSimulation::Auto,
        ParticleRenderMode $renderMode = ParticleRenderMode::Billboard,
        float $stretch = 0.0,
        float $radialSpeed = 0.0,
        float $spawnRadius = 0.0,
        float $spawnRadiusJitter = 0.0,
        float $activeDistance = 0.0,
        int $radialSpokes = 0,
    ) {
        $this->radialSpokes   = $radialSpokes;
        $this->simulation     = $simulation;
        $this->renderMode     = $renderMode;
        $this->stretch        = $stretch;
        $this->radialSpeed    = $radialSpeed;
        $this->spawnRadius    = $spawnRadius;
        $this->spawnRadiusJitter = $spawnRadiusJitter;
        $this->activeDistance = $activeDistance;
        $this->meshId         = $meshId;
        $this->materialId     = $materialId;
        $this->rate           = $rate;
        $this->lifetime       = $lifetime;
        $this->velocity       = $velocity       ?? new Vec3(0.0, 1.5, 0.0);
        $this->velocityJitter = $velocityJitter ?? new Vec3(0.5, 0.2, 0.5);
        $this->gravity        = $gravity        ?? new Vec3(0.0, -1.0, 0.0);
        $this->startSize      = $startSize;
        $this->endSize        = $endSize;
        $this->startColor     = $startColor ?? new Color(1.0, 0.7, 0.3);
        $this->endColor       = $endColor   ?? new Color(0.4, 0.1, 0.0);
        $this->maxParticles   = $maxParticles;
    }

    /**
     * Reset all live particles. Useful when a scene is reloaded so the
     * carry-over from the old world doesn't bleed into the new one.
     */
    public function clear(): void
    {
        $this->particles = [];
        $this->generation++;
        $this->gpuLedger = null;
    }

    /**
     * Live-particle count. For a GPU-simulated emitter this is the ledger's
     * conservative count (particles still counted until their slot is
     * released), otherwise the size of `$particles`.
     */
    public function count(): int
    {
        return $this->gpuLedger !== null ? $this->gpuLedger->count() : count($this->particles);
    }
}
