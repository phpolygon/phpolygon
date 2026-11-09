<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\LightCone;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\DrawLightCone;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\LightCones;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Emits a {@see DrawLightCone} for every {@see LightCone} whose {@see SpotLight} is lit
 * and whose beam is in view: position from the entity's Transform3D, direction,
 * angle, penumbra and range from the spot light, brightness = light colour x
 * intensity x density, fading out over the last quarter of `maxDistance`. The
 * {@see MAX_CONES} nearest beams are drawn. Register after the camera, before
 * Renderer3DSystem.
 */
class LightConeSystem extends AbstractSystem
{
    /** Beams drawn per frame at most (nearest first). */
    public const int MAX_CONES = 16;

    public function __construct(private readonly RenderCommandList $commandList) {}

    public function update(World $world, float $dt): void
    {
        // Render-only.
    }

    public function render(World $world): void
    {
        $camera = $this->commandList->lastOfType(SetCamera::class);
        if ($camera === null) {
            return;
        }
        foreach (self::cones($world, $camera) as $cone) {
            $this->commandList->add($cone);
        }
    }

    /**
     * The beams $camera sees, nearest first.
     *
     * @return list<DrawLightCone>
     */
    public static function cones(World $world, SetCamera $camera): array
    {
        $eye = ScreenProjection::cameraPosition($camera->viewMatrix);
        $planes = getenv('PHPOLYGON_NO_CULL') === '1' ? null : Frustum::planes($camera->projectionMatrix, $camera->viewMatrix);
        $found = [];
        foreach ($world->query(LightCone::class, SpotLight::class, Transform3D::class) as $entity) {
            $cone = $entity->get(LightCone::class);
            $light = $entity->get(SpotLight::class);
            $strength = $light->intensity * $cone->density;
            if (!$cone->enabled || $strength <= 1e-4 || $light->range <= 0.0 || $cone->length <= 0.0 || $light->angle <= 0.0 || $light->direction->length() < 1e-6) {
                continue;
            }
            $apex = $entity->get(Transform3D::class)->getWorldPosition();
            $length = $light->range * min(1.0, $cone->length);
            $angle = min($light->angle, LightCones::MAX_ANGLE);
            [$centre, $radius] = LightCones::boundingSphere($apex, $light->direction, $angle, $length);
            if ($planes !== null && Frustum::sphereOutside($planes, $centre->x, $centre->y, $centre->z, $radius)) {
                continue;
            }
            $distance = max(0.0, $centre->sub($eye)->length() - $radius);
            $fade = self::distanceFade($distance, $cone->maxDistance);
            if ($fade <= 0.0) {
                continue;
            }
            $soft = max($light->penumbra, $cone->edgeSoftness);
            $found[] = [$distance, new DrawLightCone(
                $apex,
                $light->direction->normalize(),
                $angle,
                $angle * (1.0 - min(1.0, max(0.0, $soft))),
                $length,
                $light->range,
                $light->color,
                $strength * $fade,
                $cone->axisFade,
            )];
        }
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $f): DrawLightCone => $f[1], array_slice($found, 0, self::MAX_CONES));
    }

    /** 1 up to three quarters of $maxDistance, smoothly 0 at it (0 for a distance limit of 0 or less). */
    public static function distanceFade(float $distance, float $maxDistance): float
    {
        if ($maxDistance <= 0.0) {
            return 0.0;
        }
        $t = ($distance - 0.75 * $maxDistance) / (0.25 * $maxDistance);
        $t = max(0.0, min(1.0, $t));

        return 1.0 - $t * $t * (3.0 - 2.0 * $t);
    }
}
