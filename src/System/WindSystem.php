<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Weather;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Advances Wind component time and modulates intensity.
 *
 * - Accumulates wind time (consumer systems use wind->time for phase offsets)
 * - Applies layered sine-wave gusts around baseIntensity
 * - Reads Weather.stormIntensity when present and amplifies wind accordingly
 */
class WindSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        // Read storm intensity from the scene's Weather entity (if any)
        $stormIntensity = 0.0;
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            $stormIntensity = $weather->stormIntensity;
            break;
        }

        foreach ($world->query(Wind::class) as $entity) {
            $wind = $entity->get(Wind::class);

            $wind->time += $dt;
            $t = $wind->time;

            // Layered gusts: slow swell + mid turbulence + fast flicker
            $gust = sin($t * 0.31) * 0.40
                  + sin($t * 0.97) * 0.25
                  + sin($t * 2.10) * 0.10
                  + sin($t * 4.70) * 0.05;

            // Storm amplifies both base and gust magnitude
            $stormBoost = 1.0 + $stormIntensity * 1.5;

            $wind->intensity = max(
                0.0,
                $wind->baseIntensity * $stormBoost + $gust * $wind->gustiness * $stormBoost,
            );
        }
    }
}
