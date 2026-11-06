<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\ParticleRenderMode;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Rain streaks around the viewer: ONE GPU particle emitter that rides
 * {@see HEIGHT} metres above the active {@see Camera3DComponent} (its entity plus
 * eye offset)
 * and drops velocity-stretched drops on a disc of {@see RADIUS} metres. The rate
 * follows {@see Weather::$rainIntensity}, the slant the {@see Wind}. In a hall
 * around the camera ({@see InteriorVolumeSystem::shelterAt()}) no new drops are born; the ones in
 * the air run out.
 *
 * With a water surface given, an eye below it gets no rain either.
 *
 * Owns the emitter entity, the streak mesh and its material; register it after
 * the weather systems. Nothing is spawned while it is dry.
 */
class RainShowerSystem extends AbstractSystem
{
    public const string MESH = 'rain_streak';
    public const string MATERIAL = 'rain_streak';

    /** Drops per second at full rain. */
    public const float MAX_RATE = 7000.0;
    /** Emitter height above the player and the disc the drops start on (m). */
    public const float HEIGHT = 14.0;
    public const float RADIUS = 24.0;
    /** No drops closer to the eye than this (m): they would cross the view as bars. */
    public const float INNER_RADIUS = 2.0;
    /** Fall speed of a drop (m/s) and horizontal drift per unit of wind (m/s). */
    public const float FALL_SPEED = 16.0;
    public const float WIND_DRIFT = 4.0;

    private ?int $emitterId = null;

    /** @param ?float $waterSurfaceY world height of open water; below it no rain is born */
    public function __construct(private readonly ?float $waterSurfaceY = null)
    {
    }

    public function update(World $world, float $dt): void
    {
        $weather = null;
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            break;
        }
        $player = null;
        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $camera = $entity->get(Camera3DComponent::class);
            if (!$camera->active) {
                continue;
            }
            $player = $entity->get(Transform3D::class)->getWorldPosition()->add($camera->eyeOffset);
            break;
        }
        if ($weather === null || $player === null) {
            return;
        }
        $underwater = $this->waterSurfaceY !== null && $player->y < $this->waterSurfaceY;
        $rate = $underwater ? 0.0 : self::rateFor($weather->rainIntensity, InteriorVolumeSystem::shelterAt($world, $player));
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
        $drift = new Vec3(0.0, 0.0, 0.0);
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $entity->get(Wind::class);
            $drift = $wind->direction->mul(self::WIND_DRIFT * $wind->intensity);
            break;
        }
        $emitter->velocity = new Vec3($drift->x, -self::FALL_SPEED, $drift->z);
        $world->getComponent($this->emitterId, Transform3D::class)->position = new Vec3($player->x, $player->y + self::HEIGHT, $player->z);

        // The drops catch the sky: their glow follows the daylight (a little at night).
        $daylight = 1.0;
        foreach ($world->query(DayNightCycle::class) as $entity) {
            $daylight = $entity->get(DayNightCycle::class)->getSunHeight();
            break;
        }
        $this->registerMaterial(self::glowFor($daylight));
    }

    /** Emission strength of the drops for a sun height (0 horizon/night .. 1 zenith). */
    public static function glowFor(float $sunHeight): float
    {
        return 0.05 + 0.2 * min(1.0, max(0.0, $sunHeight) * 2.5);
    }

    private float $glow = -1.0;

    private function registerMaterial(float $glow): void
    {
        if (abs($glow - $this->glow) < 0.005) {
            return;
        }
        $this->glow = $glow;
        // Drops read lighter than what is behind them: they catch the sky.
        MaterialRegistry::register(self::MATERIAL, new Material(
            albedo: new Color(0.85, 0.88, 0.92),
            roughness: 0.15,
            emission: new Color($glow, $glow * 1.08, $glow * 1.2),
            alpha: 0.2,
        ));
    }

    /** Drops per second for a rain intensity (0..1) and shelter (0..1). */
    public static function rateFor(float $rainIntensity, float $shelter): float
    {
        if ($rainIntensity <= 0.05 || $shelter >= 0.5) {
            return 0.0;
        }

        return self::MAX_RATE * min(1.0, $rainIntensity);
    }

    private function createEmitter(World $world): int
    {
        if (!MeshRegistry::has(self::MESH)) {
            MeshRegistry::register(self::MESH, SphereMesh::generate(0.5, 4, 6));
        }
        $this->registerMaterial(self::glowFor(1.0));
        // Long enough to fall from the emitter to below the player's feet.
        $lifetime = (self::HEIGHT + 3.0) / self::FALL_SPEED;
        $emitter = new ParticleEmitter(
            meshId: self::MESH,
            materialId: self::MATERIAL,
            rate: 0.0,
            lifetime: $lifetime,
            velocity: new Vec3(0.0, -self::FALL_SPEED, 0.0),
            velocityJitter: new Vec3(0.3, 1.5, 0.3),
            gravity: new Vec3(0.0, 0.0, 0.0),
            startSize: 0.009,
            endSize: 0.009,
            startColor: new Color(0.8, 0.85, 0.9),
            endColor: new Color(0.8, 0.85, 0.9),
            maxParticles: (int) ceil(self::MAX_RATE * $lifetime) + 64,
            renderMode: ParticleRenderMode::Stretched,
            stretch: 0.045,
            spawnRadius: (self::RADIUS + self::INNER_RADIUS) * 0.5,
            spawnRadiusJitter: (self::RADIUS - self::INNER_RADIUS) * 0.5,
        );

        return $world->createEntity()
            ->attach(new Transform3D())
            ->attach($emitter)
            ->id;
    }
}
