<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Wind state for the scene. Attach to a single entity per scene.
 * Driven by WindSystem, read by PalmSwaySystem, WaveSystem, CloudSystem, etc.
 */
#[Serializable]
#[Category('Environment')]
class Wind extends AbstractComponent
{
    /** Current wind intensity (0 = calm, 1 = strong breeze, >1 = storm) */
    #[Hidden]
    public float $intensity;

    /** Accumulated wind time — use this instead of a local timer in consumer systems */
    #[Hidden]
    public float $time;

    /** Base wind speed (used as resting intensity target) */
    #[Property]
    public float $baseIntensity;

    /** How fast gusts change intensity (higher = more turbulent) */
    #[Property]
    public float $gustiness;

    /** Minimum intensity clamp (set by EnvironmentalSystem based on weather) */
    #[Hidden]
    public float $minIntensity;

    /** Maximum intensity clamp (set by EnvironmentalSystem based on weather) */
    #[Hidden]
    public float $maxIntensity;

    /** Dominant wind direction (normalized Vec3, updated by WindSystem) */
    #[Hidden]
    public Vec3 $direction;

    public function __construct(
        float $baseIntensity = 0.5,
        float $gustiness = 0.3,
    ) {
        $this->baseIntensity = $baseIntensity;
        $this->gustiness     = $gustiness;
        $this->intensity     = $baseIntensity;
        $this->time          = 0.0;
        $this->minIntensity  = 0.0;
        $this->maxIntensity  = 2.0;
        $this->direction     = new Vec3(1.0, 0.0, 0.0);
    }
}
