<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\AtmosphericState;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\World;

/**
 * Full atmospheric environment simulation.
 *
 * Extends EnvironmentalSystem (Season→Weather→DayNight coupling) with a
 * physics-informed atmosphere layer:
 *
 *  1. Air pressure (Luftdruck) — slow synoptic drift driven by season and
 *     a Perlin-like multi-octave oscillation. Falling pressure anticipates
 *     bad weather; rising pressure clears it.
 *
 *  2. Pressure → Weather coupling — pressure directly modifies the humidity
 *     and cloud targets that WeatherSystem uses, so weather deterioration
 *     starts before rain arrives (barometric forecast).
 *
 *  3. Dew point — August–Roche–Magnus approximation. When temperature
 *     approaches dew point, fog formation probability rises.
 *
 *  4. Thermal convection — daytime sun heating triggers thermals that seed
 *     cumulus cloud formation and raise humidity slightly.
 *
 *  5. Atmospheric visibility — composite of fog, precipitation, sandstorm,
 *     and humidity haze. Stored on AtmosphericState for DayNightSystem /
 *     other systems to consume (e.g. SetFog far-plane).
 *
 * Register BEFORE PrecipitationSystem and DayNightSystem.
 * Requires an entity with AtmosphericState + Weather + DayNightCycle.
 */
class AtmosphericEnvironmentalSystem extends EnvironmentalSystem
{
    // Standard atmosphere constants
    private const ISA_PRESSURE   = 1013.25; // hPa at sea level
    private const PRESSURE_LOW   = 1000.0;  // below = low pressure system
    private const PRESSURE_HIGH  = 1020.0;  // above = high pressure system
    private const PRESSURE_STORM = 980.0;   // below = severe low / storm

    // Base visibility (game units) for clear atmospheric conditions
    private const VISIBILITY_MAX = 30000.0;

    public function update(World $world, float $dt): void
    {
        // Run EnvironmentalSystem first (Season→Weather→DayNight coupling)
        parent::update($world, $dt);

        $atmo     = $this->findAtmosphericState($world);
        $weather  = $this->findWeather($world);
        $season   = $this->findSeason($world);
        $dayNight = $this->findDayNight($world);

        if ($atmo === null || $weather === null) {
            return;
        }

        $atmo->simulationTime += $dt;

        $this->simulatePressure($atmo, $season, $dt);
        $this->couplePressureToWeather($atmo, $weather);
        $this->simulateDewPoint($atmo, $weather);
        $this->simulateThermals($atmo, $weather, $dayNight, $dt);
        $this->calculateVisibility($atmo, $weather);
    }

    // ── Pressure simulation ────────────────────────────────────────────────

    /**
     * Simulates synoptic-scale pressure variation.
     *
     * Two oscillators with incommensurate periods model realistic aperiodic
     * pressure swings (similar to summed sine weather models):
     *  - Slow wave  (~3 day period) — synoptic systems crossing the region
     *  - Fast wave  (~18 h period)  — mesoscale disturbances
     *
     * Season modifies the base: winter tends lower pressure (stormier),
     * summer tends higher (settled anticyclones).
     */
    private function simulatePressure(AtmosphericState $atmo, ?Season $season, float $dt): void
    {
        $t = $atmo->simulationTime;

        // Seasonal base pressure: summer high, winter low (±8 hPa swing)
        $seasonalBase = 0.0;
        if ($season !== null) {
            // yearProgress 0.25 = summer solstice (+8 hPa), 0.75 = winter (−8 hPa)
            $seasonalBase = sin(($season->yearProgress - 0.25) * 2.0 * M_PI) * 8.0;
        }

        // Synoptic oscillation: slow (259 200 s ≈ 3 days) + fast (64 800 s ≈ 18 h)
        $slowWave = sin($t / 259200.0 * 2.0 * M_PI) * 18.0
                  + sin($t / 173000.0 * 2.0 * M_PI + 1.3) * 8.0;  // second slow mode
        $fastWave = sin($t /  64800.0 * 2.0 * M_PI + 0.7) * 6.0
                  + sin($t /  43200.0 * 2.0 * M_PI + 2.1) * 4.0;  // second fast mode

        $target = self::ISA_PRESSURE + $seasonalBase + $slowWave + $fastWave;
        $target = max(945.0, min(1050.0, $target));

        // Smooth towards target (pressure changes slowly in reality)
        $speed = 0.05 * $dt; // ~20 s to close 1 hPa gap — realistic synoptic speed
        $atmo->pressurePrev   = $atmo->airPressure;
        $atmo->airPressure   += ($target - $atmo->airPressure) * $speed;

        // Trend: hPa/s (positive = rising, negative = falling)
        $atmo->pressureTrend  = ($atmo->airPressure - $atmo->pressurePrev) / max($dt, 0.001);
    }

    // ── Pressure → Weather coupling ────────────────────────────────────────

    /**
     * Low pressure drives humidity and cloud formation before precipitation
     * arrives — the barometric "forecast" effect.
     */
    private function couplePressureToWeather(AtmosphericState $atmo, Weather $weather): void
    {
        $p = $atmo->airPressure;

        if ($p < self::PRESSURE_LOW) {
            // Low pressure: push humidity and clouds upward
            $lowFactor = (self::PRESSURE_LOW - $p) / (self::PRESSURE_LOW - self::PRESSURE_STORM);
            $lowFactor = max(0.0, min(1.0, $lowFactor));

            // Nudge humidity towards 0.5 + lowFactor * 0.5
            $humidityTarget = 0.5 + $lowFactor * 0.5;
            $weather->humidity = min($weather->humidity + $lowFactor * 0.002, $humidityTarget);

            // Rapidly falling pressure accelerates cloud formation
            if ($atmo->pressureTrend < -0.005) {
                $fallBoost = min(1.0, abs($atmo->pressureTrend) / 0.02);
                $weather->cloudCoverage = min(1.0, $weather->cloudCoverage + $fallBoost * 0.003);
            }
        } elseif ($p > self::PRESSURE_HIGH) {
            // High pressure: dry, clear — gently reduce humidity and cloud cover
            $highFactor = ($p - self::PRESSURE_HIGH) / (1050.0 - self::PRESSURE_HIGH);
            $highFactor = max(0.0, min(1.0, $highFactor));

            $weather->humidity      = max($weather->humidity - $highFactor * 0.001, 0.1);
            $weather->cloudCoverage = max($weather->cloudCoverage - $highFactor * 0.002, 0.0);
        }
    }

    // ── Dew point ──────────────────────────────────────────────────────────

    /**
     * August–Roche–Magnus approximation:
     *   Td = T − ((100 − RH) / 5)
     * where RH is relative humidity in percent.
     *
     * When temperature − dewPoint < 2 °C, fog formation probability is high.
     * This is fed back into Weather::fogDensity via a gentle nudge so WeatherSystem
     * can still override it with its own wind/humidity logic.
     */
    private function simulateDewPoint(AtmosphericState $atmo, Weather $weather): void
    {
        $rh = $weather->humidity * 100.0;
        $atmo->dewPoint = $weather->temperature - ((100.0 - $rh) / 5.0);

        $tempSpread = $weather->temperature - $atmo->dewPoint;
        if ($tempSpread < 3.0 && $tempSpread >= 0.0) {
            // Near-dew conditions — nudge fog density up (WeatherSystem may also act)
            $fogPotential = 1.0 - ($tempSpread / 3.0);
            $weather->fogDensity = min(1.0, $weather->fogDensity + $fogPotential * 0.005);
        }
    }

    // ── Thermal convection ─────────────────────────────────────────────────

    /**
     * Solar heating of the ground creates thermals that lift humid air,
     * forming cumulus clouds and slightly increasing local humidity.
     *
     * Thermals are strong when:
     *   - Sun is high (midday)
     *   - Surface is warm (summer / high temperature)
     *   - Initial humidity is moderate (not already saturated)
     */
    private function simulateThermals(
        AtmosphericState $atmo,
        Weather $weather,
        ?DayNightCycle $dayNight,
        float $dt,
    ): void {
        if ($dayNight === null) {
            $atmo->thermalIntensity *= max(0.0, 1.0 - 0.5 * $dt);
            return;
        }

        $sunHeight = $dayNight->getSunHeight(); // 0 = horizon, 1 = zenith
        $warmGround = max(0.0, ($weather->temperature - 15.0) / 20.0); // 0 at 15°C, 1 at 35°C
        $moistAvail = max(0.0, min(1.0, $weather->humidity - 0.2) / 0.6); // needs some moisture

        $thermalTarget = $sunHeight * $warmGround * $moistAvail;
        $atmo->thermalIntensity += ($thermalTarget - $atmo->thermalIntensity) * 0.3 * $dt;
        $atmo->thermalIntensity = max(0.0, min(1.0, $atmo->thermalIntensity));

        if ($atmo->thermalIntensity > 0.3) {
            // Thermals lift moisture → cumulus development
            $liftEffect = ($atmo->thermalIntensity - 0.3) / 0.7;
            $weather->cloudCoverage = min(1.0, $weather->cloudCoverage + $liftEffect * 0.001);
            // Slight humidity increase at cloud base (evapotranspiration)
            $weather->humidity = min(1.0, $weather->humidity + $liftEffect * 0.0005);
        }
    }

    // ── Visibility ─────────────────────────────────────────────────────────

    /**
     * Composite atmospheric visibility (game units).
     *
     * Each factor attenuates visibility multiplicatively. Order of severity:
     *   sandstorm > fog > snow > rain > storm haze > humidity haze
     */
    private function calculateVisibility(AtmosphericState $atmo, Weather $weather): void
    {
        $vis = self::VISIBILITY_MAX;

        // Sandstorm: almost zero visibility
        if ($weather->sandstormIntensity > 0.0) {
            $vis *= max(0.02, 1.0 - $weather->sandstormIntensity * 0.98);
        }

        // Dense fog: reduces to < 200 at full intensity
        if ($weather->fogDensity > 0.0) {
            $vis *= max(0.005, 1.0 - $weather->fogDensity * 0.98);
        }

        // Heavy snow (larger flakes = more scattering than rain)
        if ($weather->snowIntensity > 0.0) {
            $vis *= max(0.05, 1.0 - $weather->snowIntensity * 0.90);
        }

        // Rain: moderate scattering
        if ($weather->rainIntensity > 0.0) {
            $vis *= max(0.10, 1.0 - $weather->rainIntensity * 0.80);
        }

        // Storm haze (electrical discharge + aerosols)
        if ($weather->stormIntensity > 0.0) {
            $vis *= max(0.20, 1.0 - $weather->stormIntensity * 0.50);
        }

        // Humidity haze: slight reduction even without precipitation
        $hazeReduction = max(0.0, $weather->humidity - 0.4) / 0.6 * 0.35;
        $vis *= max(0.65, 1.0 - $hazeReduction);

        $atmo->visibility = max(50.0, $vis);
    }

    // ── ECS queries ────────────────────────────────────────────────────────

    private function findAtmosphericState(World $world): ?AtmosphericState
    {
        foreach ($world->query(AtmosphericState::class) as $entity) {
            return $entity->get(AtmosphericState::class);
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
