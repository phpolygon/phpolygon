<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\ParticleRenderMode;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Leaves blown through the air in strong wind: ONE particle emitter riding a few
 * metres above the active {@see Camera3DComponent}, its leaves (small two-sided
 * quads in the game's leaf colour) carried along the {@see Wind} and sinking
 * slowly. Nothing below {@see START} wind intensity, full rate from {@see FULL};
 * none in an interior volume ({@see InteriorVolumeSystem::shelterAt()}) or with
 * the eye below the optional water surface.
 */
class WindLeavesSystem extends AbstractSystem
{
    public const string MESH = 'wind_leaf';
    public const string MATERIAL = 'wind_leaf';

    /** Wind intensity where the first leaves fly and where the rate is full. */
    public const float START = 0.45;
    public const float FULL = 0.9;
    /** Leaves per second at full rate. */
    public const float MAX_RATE = 90.0;
    /** Height above the eye and the disc the leaves start on (m). */
    public const float HEIGHT = 3.0;
    public const float RADIUS = 12.0;
    /** Flight speed per unit of wind (m/s). */
    public const float SPEED = 7.0;

    private ?int $emitterId = null;

    /**
     * @param ?Color $leafColor albedo of the leaves (null = a dry green-brown)
     * @param ?float $waterSurfaceY below it no leaves are born
     */
    public function __construct(
        private readonly ?Color $leafColor = null,
        private readonly ?float $waterSurfaceY = null,
    ) {
    }

    public function update(World $world, float $dt): void
    {
        $wind = null;
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $entity->get(Wind::class);
            break;
        }
        $eye = null;
        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $camera = $entity->get(Camera3DComponent::class);
            if ($camera->active) {
                $eye = $entity->get(Transform3D::class)->getWorldPosition()->add($camera->eyeOffset);
                break;
            }
        }
        if ($wind === null || $eye === null) {
            return;
        }
        $sheltered = InteriorVolumeSystem::shelterAt($world, $eye) > 0.0
            || ($this->waterSurfaceY !== null && $eye->y < $this->waterSurfaceY);
        $rate = $sheltered ? 0.0 : self::rateFor($wind->intensity);
        if ($this->emitterId === null) {
            if ($rate <= 0.0) {
                return;
            }
            $this->emitterId = $this->createEmitter($world);
        }
        if (!$world->isAlive($this->emitterId)) {
            $this->emitterId = null;
            return;
        }
        $emitter = $world->getComponent($this->emitterId, ParticleEmitter::class);
        $emitter->rate = $rate;
        $d = $wind->direction->length() > 1e-6 ? $wind->direction->normalize() : new Vec3(1.0, 0.0, 0.0);
        $emitter->velocity = new Vec3($d->x * self::SPEED * $wind->intensity, 0.4, $d->z * self::SPEED * $wind->intensity);
        // Upwind of the eye, so the leaves cross the view instead of leaving it.
        $world->getComponent($this->emitterId, Transform3D::class)->position = new Vec3(
            $eye->x - $d->x * 6.0,
            $eye->y + self::HEIGHT,
            $eye->z - $d->z * 6.0,
        );
    }

    /** Leaves per second for a wind intensity. */
    public static function rateFor(float $intensity): float
    {
        if ($intensity <= self::START) {
            return 0.0;
        }

        return self::MAX_RATE * min(1.0, ($intensity - self::START) / (self::FULL - self::START));
    }

    private function createEmitter(World $world): int
    {
        if (!MeshRegistry::has(self::MESH)) {
            // A leaf: a pointed rhombus, not a card.
            MeshRegistry::register(self::MESH, new \PHPolygon\Geometry\MeshData(
                [0.0, 0.5, 0.0, 0.28, 0.05, 0.0, 0.0, -0.5, 0.0, -0.28, 0.05, 0.0],
                [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0],
                [0.5, 0.0, 1.0, 0.45, 0.5, 1.0, 0.0, 0.45],
                [0, 1, 2, 0, 2, 3],
            ));
        }
        $color = $this->leafColor ?? new Color(0.36, 0.33, 0.12);
        MaterialRegistry::register(self::MATERIAL, new Material(albedo: $color, roughness: 0.8, twoSided: true));
        $emitter = new ParticleEmitter(
            meshId: self::MESH,
            materialId: self::MATERIAL,
            rate: 0.0,
            lifetime: 4.0,
            velocity: new Vec3(0.0, 0.0, 0.0),
            velocityJitter: new Vec3(1.2, 0.8, 1.2),
            gravity: new Vec3(0.0, -0.7, 0.0),
            startSize: 0.15,
            endSize: 0.13,
            startColor: $color,
            endColor: $color,
            maxParticles: (int) ceil(self::MAX_RATE * 4.0) + 16,
            renderMode: ParticleRenderMode::Billboard,
            spawnRadius: self::RADIUS * 0.5,
            spawnRadiusJitter: self::RADIUS * 0.5,
        );

        return $world->createEntity()
            ->attach(new Transform3D())
            ->attach($emitter)
            ->id;
    }
}
