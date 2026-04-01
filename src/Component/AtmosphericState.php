<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Atmospheric physical state. Driven by AtmosphericEnvironmentalSystem.
 * Attach to the same entity as Weather and DayNightCycle.
 *
 * Air pressure follows the standard atmosphere model (ISA):
 *   1013.25 hPa at sea level, decreasing with altitude.
 *   Values below 1000 hPa indicate low pressure / bad weather.
 *   Values above 1020 hPa indicate high pressure / clear weather.
 */
#[Serializable]
#[Category('Environment')]
class AtmosphericState extends AbstractComponent
{
    // ── Pressure ──────────────────────────────────────────────────────────

    /** Current sea-level air pressure in hPa (typical range: 960–1040) */
    #[Property]
    public float $airPressure;

    /** Pressure change rate (hPa/s, negative = falling = deteriorating weather) */
    #[Hidden]
    public float $pressureTrend = 0.0;

    /** Smoothed pressure from the previous frame for trend calculation */
    #[Hidden]
    public float $pressurePrev = 0.0;

    // ── Visibility ────────────────────────────────────────────────────────

    /**
     * Atmospheric visibility in game units (roughly metres).
     * Clear day: ~30 000. Dense fog: < 200. Heavy rain: ~2 000.
     */
    #[Hidden]
    public float $visibility = 30000.0;

    // ── Thermal ───────────────────────────────────────────────────────────

    /**
     * Dew point in °C (August–Roche–Magnus approximation).
     * When temperature approaches dew point, fog forms.
     */
    #[Hidden]
    public float $dewPoint = 10.0;

    /**
     * Thermal convection intensity 0–1.
     * High sun + warm ground → thermals → cumulus cloud formation.
     */
    #[Hidden]
    public float $thermalIntensity = 0.0;

    // ── Simulation clock ──────────────────────────────────────────────────

    /** Accumulated simulation time — drives the slow pressure oscillation */
    #[Hidden]
    public float $simulationTime = 0.0;

    public function __construct(
        float $airPressure = 1013.25,
    ) {
        $this->airPressure  = $airPressure;
        $this->pressurePrev = $airPressure;
    }
}
