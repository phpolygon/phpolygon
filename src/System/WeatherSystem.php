<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\Component\WeatherState;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Physics-based weather evolution. Temperature + humidity determine precipitation type.
 * Wolken sind Voraussetzung fuer Niederschlag, aber nicht jede Wolke bringt Regen.
 */
class WeatherSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        $weather = null;
        $season = null;
        $dayNight = null;

        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            break;
        }
        if ($weather === null) return;

        foreach ($world->query(Season::class) as $entity) {
            $season = $entity->get(Season::class);
            break;
        }
        foreach ($world->query(DayNightCycle::class) as $entity) {
            $dayNight = $entity->get(DayNightCycle::class);
            break;
        }

        $weather->stateTimer += $dt;

        // --- Temperature from season + time-of-day ---
        $baseTemp = $season ? $season->baseTemperature : 22.0;
        $timeOfDay = $dayNight ? $dayNight->timeOfDay : 0.5;
        // Noon warmer (+8°C), midnight cooler (-8°C)
        $dayTempMod = sin(($timeOfDay - 0.25) * 2.0 * M_PI) * 8.0;
        // Clouds dampen temperature extremes
        $cloudDamping = 1.0 - $weather->cloudCoverage * 0.4;
        $weather->temperature = $baseTemp + $dayTempMod * $cloudDamping;

        // --- Humidity from season + pseudo-random drift ---
        $baseHumidity = $season ? $season->baseHumidity : 0.5;
        $humidityDrift = sin($weather->stateTimer * 0.02) * 0.15 + sin($weather->stateTimer * 0.007) * 0.1;
        $weather->humidity = max(0.0, min(1.0, $baseHumidity + $humidityDrift));

        // --- Cloud formation ---
        $targetClouds = 0.0;
        if ($weather->humidity > 0.4) {
            $targetClouds = ($weather->humidity - 0.4) / 0.6; // 0 at 0.4, 1 at 1.0
        }
        // Smooth transition
        $cloudSpeed = 0.3 * $dt; // Clouds form/dissipate slowly
        $weather->cloudCoverage += ($targetClouds - $weather->cloudCoverage) * $cloudSpeed;
        $weather->cloudCoverage = max(0.0, min(1.0, $weather->cloudCoverage));

        // --- Precipitation ---
        $canPrecipitate = $weather->cloudCoverage > 0.6 && $weather->humidity > 0.6;

        if ($canPrecipitate) {
            $precipIntensity = ($weather->cloudCoverage - 0.6) / 0.4 * ($weather->humidity - 0.6) / 0.4;
            $precipIntensity = max(0.0, min(1.0, $precipIntensity));

            if ($weather->temperature > 2.0) {
                // Rain
                $weather->rainIntensity += ($precipIntensity - $weather->rainIntensity) * 0.5 * $dt;
                $weather->snowIntensity *= max(0.0, 1.0 - 2.0 * $dt); // Fade snow
            } else {
                // Snow
                $weather->snowIntensity += ($precipIntensity - $weather->snowIntensity) * 0.3 * $dt;
                $weather->rainIntensity *= max(0.0, 1.0 - 2.0 * $dt); // Fade rain
            }
        } else {
            // No precipitation — fade both
            $weather->rainIntensity *= max(0.0, 1.0 - 0.5 * $dt);
            $weather->snowIntensity *= max(0.0, 1.0 - 0.3 * $dt);
        }

        // Kill tiny values
        if ($weather->rainIntensity < 0.01) $weather->rainIntensity = 0.0;
        if ($weather->snowIntensity < 0.01) $weather->snowIntensity = 0.0;

        // --- Storm (needs clouds + high temp + humidity) ---
        $canStorm = $weather->cloudCoverage > 0.8 && $weather->humidity > 0.7 && $weather->temperature > 20.0;
        if ($canStorm) {
            $stormTarget = ($weather->temperature - 20.0) / 12.0 * ($weather->humidity - 0.7) / 0.3;
            $weather->stormIntensity += (min(1.0, $stormTarget) - $weather->stormIntensity) * 0.2 * $dt;
        } else {
            $weather->stormIntensity *= max(0.0, 1.0 - 0.5 * $dt);
        }
        if ($weather->stormIntensity < 0.01) $weather->stormIntensity = 0.0;

        // --- Lightning (during storms) ---
        $weather->lightningFlash *= max(0.0, 1.0 - 8.0 * $dt); // Rapid decay
        if ($weather->stormIntensity > 0.3) {
            $weather->lightningTimer += $dt;
            // Random interval: 3–10 seconds, shorter in intense storms
            $interval = 3.0 + (1.0 - $weather->stormIntensity) * 7.0;
            if ($weather->lightningTimer >= $interval) {
                $weather->lightningFlash = 1.0;
                $weather->lightningTimer = 0.0;
            }
        }

        // --- Fog (high humidity + low wind + temp near dew point) ---
        $windIntensity = 0.5; // Will be read from Wind component by EnvironmentalSystem
        foreach ($world->query(\PHPolygon\Component\Wind::class) as $entity) {
            $windIntensity = $entity->get(\PHPolygon\Component\Wind::class)->intensity;
            break;
        }

        $canFog = $weather->humidity > 0.8 && $windIntensity < 0.3;
        if ($canFog) {
            $fogTarget = ($weather->humidity - 0.8) / 0.2 * (1.0 - $windIntensity / 0.3);
            $weather->fogDensity += (min(1.0, $fogTarget) - $weather->fogDensity) * 0.2 * $dt;
        } else {
            $weather->fogDensity *= max(0.0, 1.0 - 0.3 * $dt);
        }
        if ($weather->fogDensity < 0.01) $weather->fogDensity = 0.0;

        // --- Sandstorm (strong wind + dry) ---
        $canSand = $windIntensity > 0.8 && $weather->humidity < 0.3;
        if ($canSand) {
            $weather->sandstormIntensity += (0.8 - $weather->sandstormIntensity) * 0.3 * $dt;
        } else {
            $weather->sandstormIntensity *= max(0.0, 1.0 - 0.5 * $dt);
        }
        if ($weather->sandstormIntensity < 0.01) $weather->sandstormIntensity = 0.0;

        // --- Update state label ---
        if ($weather->stormIntensity > 0.3) {
            $weather->state = WeatherState::Storm;
        } elseif ($weather->snowIntensity > 0.1) {
            $weather->state = WeatherState::Snow;
        } elseif ($weather->rainIntensity > 0.1) {
            $weather->state = WeatherState::Rain;
        } elseif ($weather->fogDensity > 0.3) {
            $weather->state = WeatherState::Fog;
        } elseif ($weather->cloudCoverage > 0.5) {
            $weather->state = WeatherState::Cloudy;
        } else {
            $weather->state = WeatherState::Clear;
        }
    }
}
