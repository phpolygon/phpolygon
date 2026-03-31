<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Orchestrates Season → Weather → DayNight coupling.
 * Runs SeasonSystem and WeatherSystem internally, then applies cross-system effects:
 * - Season.axialTilt → DayNightCycle sun elevation modifier
 * - Weather.cloudCoverage → DayNight ambient/fog darkening
 * - Weather.stormIntensity → Wind bounds modification
 * - Weather.lightningFlash → DayNight ambient flash
 *
 * Register as a single system; it delegates to SeasonSystem and WeatherSystem.
 */
class EnvironmentalSystem extends AbstractSystem
{
    private SeasonSystem $seasonSystem;
    private WeatherSystem $weatherSystem;

    public function __construct()
    {
        $this->seasonSystem = new SeasonSystem();
        $this->weatherSystem = new WeatherSystem();
    }

    public function update(World $world, float $dt): void
    {
        // 1. Advance seasons (updates axialTilt, baseTemperature, vegetation)
        $this->seasonSystem->update($world, $dt);

        // 2. Evolve weather based on season + time-of-day
        $this->weatherSystem->update($world, $dt);

        // 3. Cross-system coupling
        $this->coupleToWind($world);
        $this->coupleToDayNight($world);
    }

    /**
     * Weather modifies wind bounds: storms increase wind, calm weather reduces it.
     */
    private function coupleToWind(World $world): void
    {
        $weather = null;
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            break;
        }
        if ($weather === null) return;

        // Find Wind component and adjust bounds
        foreach ($world->query(\PHPolygon\Component\Wind::class) as $entity) {
            $wind = $entity->get(\PHPolygon\Component\Wind::class);

            // Storm increases max wind
            $wind->maxIntensity = 1.0 + $weather->stormIntensity * 0.5;
            // Rain slightly increases min wind
            $wind->minIntensity = 0.15 + $weather->rainIntensity * 0.15 + $weather->stormIntensity * 0.2;

            break;
        }
    }

    /**
     * Season and weather affect DayNight: axial tilt + cloud darkening + lightning flash.
     */
    private function coupleToDayNight(World $world): void
    {
        $season = null;
        $weather = null;
        $dayNight = null;

        foreach ($world->query(Season::class) as $entity) {
            $season = $entity->get(Season::class);
            break;
        }
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            break;
        }
        foreach ($world->query(DayNightCycle::class) as $entity) {
            $dayNight = $entity->get(DayNightCycle::class);
            break;
        }

        if ($dayNight === null) return;

        // Seasonal sun tilt (longer/shorter days)
        if ($season !== null) {
            // Store axial tilt on the DayNightCycle for DayNightSystem to read
            // Using a simple property addition approach
            $dayNight->axialTilt = $season->axialTilt;
        }

        // Cloud coverage reduces effective sun intensity (DayNightSystem reads this)
        if ($weather !== null) {
            $dayNight->cloudDarkening = $weather->cloudCoverage * 0.5;
            $dayNight->lightningFlash = $weather->lightningFlash;
        }
    }
}
