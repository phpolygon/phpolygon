<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Seasonal progression. Drives vegetation colors, temperature ranges, and sun tilt.
 * Attach to a single entity per scene.
 *
 * yearProgress: 0.0 = spring equinox, 0.25 = summer solstice,
 *               0.5 = autumn equinox, 0.75 = winter solstice
 */
#[Serializable]
#[Category('Environment')]
class Season extends AbstractComponent
{
    /** Current position in the year (0.0–1.0, wraps) */
    #[Property]
    public float $yearProgress;

    /** Real-world seconds for one full year */
    #[Property]
    public float $yearDuration;

    #[Property]
    public float $speed;

    // --- Derived values (computed by SeasonSystem) ---

    /** Sun axis tilt in degrees (-15 to +15) — added to DayNightCycle elevation */
    #[Hidden]
    public float $axialTilt = 0.0;

    /** Base temperature for this time of year (°C) */
    #[Hidden]
    public float $baseTemperature = 22.0;

    /** Base humidity for this time of year (0–1) */
    #[Hidden]
    public float $baseHumidity = 0.5;

    /**
     * How strongly the season colours the vegetation (grass, foliage): 0 = not at
     * all (plants as authored), 1 = the full map of {@see \PHPolygon\System\SeasonSystem}.
     */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $gradeStrength = 0.0;

    /** This frame's vegetation tint and desaturation (set by SeasonSystem). */
    #[Hidden]
    public ?\PHPolygon\Rendering\Color $gradeTint = null;

    #[Hidden]
    public float $gradeDesaturation = 0.0;

    public function __construct(
        float $yearProgress = 0.0,
        float $yearDuration = 480.0,
        float $speed = 1.0,
        float $gradeStrength = 0.0,
    ) {
        $this->yearProgress = $yearProgress;
        $this->yearDuration = max(1.0, $yearDuration);
        $this->speed = $speed;
        $this->gradeStrength = $gradeStrength;
    }

    /** Season name for the current progress */
    public function getSeasonName(): string
    {
        $p = $this->yearProgress;
        if ($p < 0.125 || $p >= 0.875) return 'spring';
        if ($p < 0.375) return 'summer';
        if ($p < 0.625) return 'autumn';
        return 'winter';
    }

    /** Blend factor 0–1 for how "deep" into the current season we are */
    public function getSeasonDepth(): float
    {
        $p = fmod($this->yearProgress + 0.125, 0.25);
        return $p / 0.25;
    }
}
