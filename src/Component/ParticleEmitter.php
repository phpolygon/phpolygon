<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

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
    ) {
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
    }

    /** Live-particle count helper for callers that don't want to count() the array. */
    public function count(): int
    {
        return count($this->particles);
    }
}
