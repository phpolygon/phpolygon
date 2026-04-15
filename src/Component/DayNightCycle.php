<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Controls the day/night cycle. Attach to a single entity per scene.
 *
 * timeOfDay: 0.0 = midnight, 0.25 = sunrise, 0.5 = noon, 0.75 = sunset
 */
#[Serializable]
#[Category('Environment')]
class DayNightCycle extends AbstractComponent
{
    /** Current time of day (0.0–1.0, wraps) */
    #[Property]
    public float $timeOfDay;

    /** Real-world seconds for one full day cycle */
    #[Property]
    public float $dayDuration;

    /** Speed multiplier (1.0 = normal, 0 = frozen) */
    #[Property]
    public float $speed;

    /** Pause the cycle (e.g., for cutscenes) */
    #[Property]
    public bool $paused;

    /** How many in-game days make one lunar cycle (real: 29.5 days) */
    #[Property]
    public float $lunarCycleDays;

    /** Current day counter (fractional, incremented by 1.0 per full day) */
    #[Property]
    public float $dayCount;

    /** Seasonal sun axis tilt in degrees (set by EnvironmentalSystem) */
    #[Hidden]
    public float $axialTilt = 0.0;

    /** Cloud-based sun darkening factor 0–1 (set by EnvironmentalSystem) */
    #[Hidden]
    public float $cloudDarkening = 0.0;

    /** Lightning flash intensity 0–1 (set by EnvironmentalSystem) */
    #[Hidden]
    public float $lightningFlash = 0.0;

    public function __construct(
        float $timeOfDay = 0.35,
        float $dayDuration = 600.0,
        float $speed = 1.0,
        bool $paused = false,
        float $lunarCycleDays = 8.0,
        float $dayCount = 0.0,
    ) {
        $this->timeOfDay = $timeOfDay;
        $this->dayDuration = max(1.0, $dayDuration);
        $this->speed = $speed;
        $this->paused = $paused;
        $this->lunarCycleDays = $lunarCycleDays;
        $this->dayCount = $dayCount;
    }

    /**
     * Moon phase (0.0–1.0): 0 = new moon, 0.5 = full moon, 1.0 = new moon again.
     */
    public function getMoonPhase(): float
    {
        $phase = fmod($this->dayCount / $this->lunarCycleDays, 1.0);
        return $phase < 0 ? $phase + 1.0 : $phase;
    }

    /**
     * Sun elevation in degrees (~-80 to +80).
     * Positive = above horizon, negative = below.
     *
     * Axial tilt affects BOTH peak elevation AND day length:
     * - Summer (tilt > 0): sun rises earlier, sets later, peaks higher
     * - Winter (tilt < 0): sun rises later, sets earlier, peaks lower
     */
    public function getSunElevation(): float
    {
        // Day length shift: axial tilt compresses/expands the night portion.
        // At tilt=+10°, sunrise shifts ~0.03 earlier, sunset ~0.03 later (≈+12% day).
        // At tilt=-10°, opposite (≈-12% day, longer nights).
        $tiltNorm = $this->axialTilt / 23.5; // -1..+1
        $dayShift = $tiltNorm * 0.04; // max ±4% of day cycle

        // Shift the time to stretch/compress daytime portion
        $t = $this->timeOfDay;
        // Remap: noon (t=0.5) stays fixed, sunrise/sunset shift symmetrically
        $centeredT = $t - 0.5; // -0.5..+0.5
        // Compress night (negative elevation) and expand day (positive) or vice versa
        $adjustedT = 0.5 + $centeredT * (1.0 - $dayShift * 2.0 * ($centeredT > 0 ? -1 : 1));
        // Keep in 0..1
        $adjustedT = $adjustedT - floor($adjustedT);

        return sin($adjustedT * 2.0 * M_PI - M_PI * 0.5) * 80.0 + $this->axialTilt;
    }

    /**
     * Whether the sun is above the horizon.
     */
    public function isDaytime(): bool
    {
        return $this->getSunElevation() > -5.0;
    }

    /**
     * Normalized sun height (0 = horizon, 1 = zenith). Clamped to 0 when below horizon.
     */
    public function getSunHeight(): float
    {
        return max(0.0, $this->getSunElevation() / 80.0);
    }
}
