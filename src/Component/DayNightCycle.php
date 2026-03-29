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
     * Sun elevation in degrees (-80 to +80).
     * Positive = above horizon, negative = below.
     */
    public function getSunElevation(): float
    {
        return sin($this->timeOfDay * 2.0 * M_PI - M_PI * 0.5) * 80.0;
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
