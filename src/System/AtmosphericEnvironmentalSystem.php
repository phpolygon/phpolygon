<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Atmosphere;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\World;

/**
 * Full atmospheric environment simulation.
 *
 * Extends EnvironmentalSystem (Season→Weather→DayNight coupling) with a
 * physics-informed atmosphere layer, all stored on the Atmosphere component:
 *
 *  1. Air pressure (Luftdruck) — slow synoptic drift driven by season and a
 *     multi-octave oscillation. Falling pressure anticipates bad weather;
 *     rising pressure clears it.
 *
 *  2. Pressure → Weather coupling — pressure modifies humidity and cloud
 *     targets that WeatherSystem uses (barometric forecast effect).
 *
 *  3. Cloud type fractions — pressure and storm intensity drive the ratio of
 *     cumulus / stratus / cumulonimbus clouds (read by CloudSystem).
 *
 *  4. Cloud base altitude — thermals and pressure adjust the altitude where
 *     clouds form (read by CloudSystem to position cloud entities).
 *
 *  5. Dew point — August–Roche–Magnus approximation. Near dew point → fog.
 *
 *  6. Thermal convection — daytime sun heating seeds cumulus and raises humidity.
 *
 *  7. Atmospheric visibility — composite of fog, precipitation, sandstorm, and
 *     humidity haze (stored on Atmosphere for DayNightSystem / LOD use).
 *
 * Requires an entity with Atmosphere + Weather components.
 * Register BEFORE PrecipitationSystem and DayNightSystem.
 */
class AtmosphericEnvironmentalSystem extends EnvironmentalSystem
{
    private const ISA_PRESSURE   = 1013.25;
    private const PRESSURE_LOW   = 1000.0;
    private const PRESSURE_HIGH  = 1020.0;
    private const PRESSURE_STORM = 980.0;
    private const VISIBILITY_MAX = 30000.0;

    /**
     * The nudges and blend factors below were tuned as amounts per update at
     * 60 updates per second. They are applied as rates over elapsed time, so
     * the atmosphere evolves at the same pace at any frame rate.
     */
    private const TUNED_STEPS_PER_SECOND = 60.0;

    public function update(World $world, float $dt): void
    {
        // Season→Weather→DayNight coupling (EnvironmentalSystem)
        parent::update($world, $dt);

        $atmo     = $this->findAtmosphere($world);
        $weather  = $this->findWeather($world);
        $season   = $this->findSeason($world);
        $dayNight = $this->findDayNight($world);

        if ($atmo === null || $weather === null) {
            return;
        }

        // The atmosphere is part of the weather and runs on the weather's
        // clock (Weather::$timeScale), so a scene that slows its weather to a
        // longer day slows pressure, fog and thermals with it.
        $weatherDt = max(0.0, $dt) * max(0.0, $weather->timeScale);

        $atmo->simulationTime += $weatherDt;

        $this->simulatePressure($atmo, $season, $weatherDt);
        $this->couplePressureToWeather($atmo, $weather, $weatherDt);
        $this->simulateDewPoint($atmo, $weather, $weatherDt);
        $this->simulateThermals($atmo, $weather, $dayNight, $weatherDt);
        $this->updateCloudTypes($atmo, $weather, max(0.0, $dt));
        $this->calculateVisibility($atmo, $weather);
    }

    /** An amount tuned per 60 Hz update, accumulated over $seconds. */
    private static function perTunedStep(float $amount, float $seconds): float
    {
        return $amount * self::TUNED_STEPS_PER_SECOND * $seconds;
    }

    /**
     * Blend factor for "move $fraction of the way per 60 Hz update" over
     * $seconds – the same approach curve at any frame rate.
     */
    private static function approachFactor(float $fraction, float $seconds): float
    {
        return 1.0 - (1.0 - $fraction) ** (self::TUNED_STEPS_PER_SECOND * $seconds);
    }

    // ── Air pressure ───────────────────────────────────────────────────────

    /**
     * Synoptic pressure simulation via two overlapping oscillators:
     *  - Slow (~3 day) and fast (~18 h) waves reproduce aperiodic pressure swings.
     * Season shifts the base: summer anticyclones (high), winter lows (storm belt).
     */
    private function simulatePressure(Atmosphere $atmo, ?Season $season, float $dt): void
    {
        $t = $atmo->simulationTime;

        $seasonalBase = 0.0;
        if ($season !== null) {
            $seasonalBase = sin(($season->yearProgress - 0.25) * 2.0 * M_PI) * 8.0;
        }

        $slowWave = sin($t / 259200.0 * 2.0 * M_PI) * 18.0
                  + sin($t / 173000.0 * 2.0 * M_PI + 1.3) * 8.0;
        $fastWave = sin($t /  64800.0 * 2.0 * M_PI + 0.7) * 6.0
                  + sin($t /  43200.0 * 2.0 * M_PI + 2.1) * 4.0;

        $target = max(945.0, min(1050.0, self::ISA_PRESSURE + $seasonalBase + $slowWave + $fastWave));

        $atmo->pressurePrev = $atmo->airPressure;
        $atmo->airPressure += ($target - $atmo->airPressure) * min(1.0, 0.05 * $dt);
        // hPa per weather second; 0 while the weather clock stands still.
        $atmo->pressureTrend = $dt > 0.0
            ? ($atmo->airPressure - $atmo->pressurePrev) / max($dt, 0.001)
            : 0.0;
    }

    // ── Pressure → Weather coupling ────────────────────────────────────────

    private function couplePressureToWeather(Atmosphere $atmo, Weather $weather, float $dt): void
    {
        $p = $atmo->airPressure;

        if ($p < self::PRESSURE_LOW) {
            $lowFactor = max(0.0, min(1.0,
                (self::PRESSURE_LOW - $p) / (self::PRESSURE_LOW - self::PRESSURE_STORM)
            ));
            $weather->humidity = min(
                $weather->humidity + self::perTunedStep($lowFactor * 0.002, $dt),
                0.5 + $lowFactor * 0.5,
            );

            if ($atmo->pressureTrend < -0.005) {
                $fallBoost = min(1.0, abs($atmo->pressureTrend) / 0.02);
                $weather->cloudCoverage = min(1.0, $weather->cloudCoverage + self::perTunedStep($fallBoost * 0.003, $dt));
            }
        } elseif ($p > self::PRESSURE_HIGH) {
            $highFactor = max(0.0, min(1.0,
                ($p - self::PRESSURE_HIGH) / (1050.0 - self::PRESSURE_HIGH)
            ));
            $weather->humidity      = max($weather->humidity - self::perTunedStep($highFactor * 0.001, $dt), 0.1);
            $weather->cloudCoverage = max($weather->cloudCoverage - self::perTunedStep($highFactor * 0.002, $dt), 0.0);
        }
    }

    // ── Dew point ──────────────────────────────────────────────────────────

    private function simulateDewPoint(Atmosphere $atmo, Weather $weather, float $dt): void
    {
        $rh = $weather->humidity * 100.0;
        $atmo->dewPoint = $weather->temperature - ((100.0 - $rh) / 5.0);

        $spread = $weather->temperature - $atmo->dewPoint;
        if ($spread >= 0.0 && $spread < 3.0) {
            $weather->fogDensity = min(1.0, $weather->fogDensity + self::perTunedStep((1.0 - $spread / 3.0) * 0.005, $dt));
        }
    }

    // ── Thermal convection ─────────────────────────────────────────────────

    private function simulateThermals(
        Atmosphere $atmo,
        Weather $weather,
        ?DayNightCycle $dayNight,
        float $dt,
    ): void {
        if ($dayNight === null) {
            $atmo->thermalIntensity *= max(0.0, 1.0 - 0.5 * $dt);
            return;
        }

        $sunHeight  = $dayNight->getSunHeight();
        $warmGround = max(0.0, ($weather->temperature - 15.0) / 20.0);
        $moistAvail = max(0.0, min(1.0, ($weather->humidity - 0.2) / 0.6));

        $thermalTarget = $sunHeight * $warmGround * $moistAvail;
        $atmo->thermalIntensity += ($thermalTarget - $atmo->thermalIntensity) * min(1.0, 0.3 * $dt);
        $atmo->thermalIntensity  = max(0.0, min(1.0, $atmo->thermalIntensity));

        if ($atmo->thermalIntensity > 0.3) {
            $lift = ($atmo->thermalIntensity - 0.3) / 0.7;
            $weather->cloudCoverage = min(1.0, $weather->cloudCoverage + self::perTunedStep($lift * 0.001, $dt));
            $weather->humidity      = min(1.0, $weather->humidity + self::perTunedStep($lift * 0.0005, $dt));
        }
    }

    // ── Cloud type fractions ───────────────────────────────────────────────

    /**
     * Derives cumulus / stratus / cumulonimbus fractions and cloud base altitude
     * from the current atmospheric state and writes them to the Atmosphere component
     * for CloudSystem to consume.
     */
    private function updateCloudTypes(Atmosphere $atmo, Weather $weather, float $dt): void
    {
        $p = $atmo->airPressure;

        // Cumulonimbus grows in deep low-pressure systems with storm activity
        $cbTarget = max(0.0, min(0.4,
            $weather->stormIntensity * 0.4 +
            max(0.0, (self::PRESSURE_STORM - $p) / self::PRESSURE_STORM) * 0.3
        ));

        // Stratus forms in cool, damp, stable (high-pressure) conditions
        $stratusTarget = max(0.0, min(0.5,
            $weather->cloudCoverage * max(0.0, (self::ISA_PRESSURE - $p) / 50.0) * 0.5
        ));

        // Cumulus: remainder after CB and stratus, enhanced by thermals
        $cumulusTarget = max(0.0, min(0.8,
            $weather->cloudCoverage * (0.5 + $atmo->thermalIntensity * 0.5)
            - $cbTarget - $stratusTarget
        ));

        // Presentation smoothing toward the targets (real time, frame-rate
        // independent): the targets themselves move at the weather's pace.
        $lerpRate = self::approachFactor(0.2, $dt);
        $atmo->cumulonimbusFraction = $atmo->cumulonimbusFraction + ($cbTarget - $atmo->cumulonimbusFraction) * $lerpRate;
        $atmo->stratusFraction      = $atmo->stratusFraction      + ($stratusTarget - $atmo->stratusFraction)  * $lerpRate;
        $atmo->cumulusFraction      = $atmo->cumulusFraction      + ($cumulusTarget - $atmo->cumulusFraction)  * $lerpRate;

        // Cloud base altitude: thermals lift it, low pressure lowers it
        $baseTarget = 45.0
            + $atmo->thermalIntensity * 15.0
            - max(0.0, (self::PRESSURE_LOW - $p) / 30.0) * 10.0;
        $atmo->cloudBaseAltitude += ($baseTarget - $atmo->cloudBaseAltitude) * self::approachFactor(0.1, $dt);
    }

    // ── Atmospheric visibility ─────────────────────────────────────────────

    private function calculateVisibility(Atmosphere $atmo, Weather $weather): void
    {
        $vis = self::VISIBILITY_MAX;

        if ($weather->sandstormIntensity > 0.0) {
            $vis *= max(0.02, 1.0 - $weather->sandstormIntensity * 0.98);
        }
        if ($weather->fogDensity > 0.0) {
            $vis *= max(0.005, 1.0 - $weather->fogDensity * 0.98);
        }
        if ($weather->snowIntensity > 0.0) {
            $vis *= max(0.05, 1.0 - $weather->snowIntensity * 0.90);
        }
        if ($weather->rainIntensity > 0.0) {
            $vis *= max(0.10, 1.0 - $weather->rainIntensity * 0.80);
        }
        if ($weather->stormIntensity > 0.0) {
            $vis *= max(0.20, 1.0 - $weather->stormIntensity * 0.50);
        }

        $hazeReduction = max(0.0, $weather->humidity - 0.4) / 0.6 * 0.35;
        $vis *= max(0.65, 1.0 - $hazeReduction);

        $atmo->visibility = max(50.0, $vis);
    }

    // ── ECS queries ────────────────────────────────────────────────────────

    private function findAtmosphere(World $world): ?Atmosphere
    {
        foreach ($world->query(Atmosphere::class) as $entity) {
            return $entity->get(Atmosphere::class);
        }
        return null;
    }

    private function findWeather(World $world): ?Weather
    {
        foreach ($world->query(Weather::class) as $entity) {
            return $entity->get(Weather::class);
        }
        return null;
    }

    private function findSeason(World $world): ?Season
    {
        foreach ($world->query(Season::class) as $entity) {
            return $entity->get(Season::class);
        }
        return null;
    }

    private function findDayNight(World $world): ?DayNightCycle
    {
        foreach ($world->query(DayNightCycle::class) as $entity) {
            return $entity->get(DayNightCycle::class);
        }
        return null;
    }
}
