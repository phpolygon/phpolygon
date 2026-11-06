<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\EmissiveAreaLight;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\RectAreaLight;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Sole writer of the point lights of {@see EmissiveAreaLight}s: each frame the light
 * takes the hue of its material's emission and an intensity of `scale` times the
 * emission's brightest channel; a material that does not glow leaves the light at 0,
 * which the renderer skips. Adds the PointLight on first sight. An entity that
 * carries a {@see RectAreaLight} (a glowing panel or band) has that light driven
 * instead, at its own size and range.
 *
 * With the world light grid many of these are affordable: a street of neon signs
 * lights its own pavement.
 */
class EmissiveAreaLightSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        foreach ($world->query(EmissiveAreaLight::class) as $entity) {
            $area = $entity->get(EmissiveAreaLight::class);
            $rect = $world->tryGetComponent($entity->id, RectAreaLight::class);
            if ($rect instanceof RectAreaLight) {
                [$rect->color, $rect->intensity] = self::lightFor($area);
                continue;
            }
            $light = $world->tryGetComponent($entity->id, PointLight::class);
            if (!$light instanceof PointLight) {
                $light = new PointLight(Color::white(), 0.0, $area->radius);
                $world->attachComponent($entity->id, $light);
            }
            [$color, $intensity] = self::lightFor($area);
            $light->color = $color;
            $light->intensity = $intensity;
            $light->radius = $area->radius;
        }
    }

    /**
     * Colour and intensity of the light for the material's current emission.
     *
     * @return array{0: Color, 1: float}
     */
    public static function lightFor(EmissiveAreaLight $area): array
    {
        $material = MaterialRegistry::get($area->materialId);
        if ($material === null) {
            return [Color::white(), 0.0];
        }
        $e = $material->emission;
        $peak = max($e->r, $e->g, $e->b);
        if ($peak < 0.01) {
            return [Color::white(), 0.0];
        }

        return [new Color($e->r / $peak, $e->g / $peak, $e->b / $peak), $peak * $area->scale];
    }
}
