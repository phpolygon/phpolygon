<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Manages rain/snow/sand particles around the player.
 * Particles are pre-spawned entities with a special 'precipitation' material ID.
 * This system repositions them each frame based on weather state.
 */
class PrecipitationSystem extends AbstractSystem
{
    private float $time = 0.0;

    public function update(World $world, float $dt): void
    {
        $this->time += $dt;

        // Find weather state
        $weather = null;
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            break;
        }

        // Find player position
        $playerPos = null;
        foreach ($world->query(\PHPolygon\Component\CharacterController3D::class, Transform3D::class) as $entity) {
            $playerPos = $entity->get(Transform3D::class)->position;
            break;
        }

        if ($weather === null || $playerPos === null) return;

        $isActive = $weather->rainIntensity > 0.05 || $weather->snowIntensity > 0.05 || $weather->sandstormIntensity > 0.05;

        // Update precipitation material based on type
        if ($weather->rainIntensity > 0.05) {
            MaterialRegistry::register('precipitation', new Material(
                albedo: new Color(0.5, 0.6, 0.8),
                roughness: 0.1,
                alpha: 0.3 + $weather->rainIntensity * 0.3,
            ));
        } elseif ($weather->snowIntensity > 0.05) {
            MaterialRegistry::register('precipitation', new Material(
                albedo: new Color(0.95, 0.95, 1.0),
                emission: new Color(0.1, 0.1, 0.12),
                roughness: 0.9,
                alpha: 0.7 + $weather->snowIntensity * 0.3,
            ));
        } elseif ($weather->sandstormIntensity > 0.05) {
            MaterialRegistry::register('precipitation', new Material(
                albedo: new Color(0.8, 0.7, 0.5),
                roughness: 0.95,
                alpha: 0.4 + $weather->sandstormIntensity * 0.3,
            ));
        }

        // Find wind for rain angle
        $windX = 0.0;
        $windZ = 0.0;
        $windIntensity = 0.0;
        foreach ($world->query(\PHPolygon\Component\Wind::class) as $entity) {
            $wind = $entity->get(\PHPolygon\Component\Wind::class);
            $windIntensity = $wind->intensity;
            $windX = $wind->direction->x * $windIntensity;
            $windZ = $wind->direction->z * $windIntensity;
            break;
        }

        // Animate precipitation particles
        $particleIndex = 0;
        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            if ($mesh->materialId !== 'precipitation') continue;

            $transform = $entity->get(Transform3D::class);

            if (!$isActive) {
                // Hide particles below world
                $transform->position = new Vec3(0, -100, 0);
                continue;
            }

            // Pseudo-random offset per particle (deterministic from index)
            $seed = $particleIndex * 73.37;
            $rx = sin($seed) * 15.0;
            $rz = cos($seed * 1.7) * 15.0;

            if ($weather->rainIntensity > 0.05) {
                // Rain: fast falling, slight wind drift
                $speed = 12.0 + sin($seed * 3.1) * 3.0;
                $y = 15.0 - fmod(($this->time * $speed + sin($seed) * 10.0), 20.0);
                $x = $playerPos->x + $rx + $windX * ($this->time * 0.5);
                $z = $playerPos->z + $rz + $windZ * ($this->time * 0.5);
                $transform->position = new Vec3($x, $playerPos->y + $y, $z);
                $transform->scale = new Vec3(0.01, 0.15 + $weather->rainIntensity * 0.1, 0.01);
            } elseif ($weather->snowIntensity > 0.05) {
                // Snow: slow falling, tumbling sideways
                $speed = 1.5 + sin($seed * 2.3) * 0.5;
                $y = 10.0 - fmod(($this->time * $speed + sin($seed) * 8.0), 14.0);
                $wobbleX = sin($this->time * 1.5 + $seed) * 0.5;
                $wobbleZ = cos($this->time * 1.2 + $seed * 0.7) * 0.5;
                $x = $playerPos->x + $rx + $wobbleX;
                $z = $playerPos->z + $rz + $wobbleZ;
                $transform->position = new Vec3($x, $playerPos->y + $y, $z);
                $transform->scale = new Vec3(0.04, 0.04, 0.04);
            } elseif ($weather->sandstormIntensity > 0.05) {
                // Sand: horizontal near ground
                $y = sin($seed * 0.5) * 1.0 + 0.3;
                $drift = fmod($this->time * 5.0 + $seed * 3.0, 30.0) - 15.0;
                $x = $playerPos->x + $drift * ($windX > 0 ? 1 : -1);
                $z = $playerPos->z + $rz * 0.3;
                $transform->position = new Vec3($x, $playerPos->y + $y, $z);
                $transform->scale = new Vec3(0.06, 0.02, 0.02);
            }

            $particleIndex++;
        }
    }
}
