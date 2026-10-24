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
 * Driven by WindSystem (gusts, storm); Renderer3DSystem turns it into the
 * frame's SetWind command, which sways cloth and foliage (Material::$cloth,
 * Material::$foliageWind) in the vertex shader. Weather, precipitation and
 * day/night systems read the intensity as well.
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

    /** Frequency of wind gusts in Hz (cycles per second) */
    #[Property]
    public float $gustFrequency;

    /** Minimum intensity clamp (set by EnvironmentalSystem based on weather) */
    #[Hidden]
    public float $minIntensity;

    /** Maximum intensity clamp (set by EnvironmentalSystem based on weather) */
    #[Hidden]
    public float $maxIntensity;

    /** Dominant wind direction (normalized Vec3, updated by WindSystem) */
    #[Hidden]
    public Vec3 $direction;

    /**
     * Scale of the foliage sway only: 1 = normal, 0.3 = reduced motion,
     * 0 = plants stand still. A player setting the game copies in.
     */
    #[Property]
    public float $foliageMotion = 1.0;

    /** Camera distance (m) where foliage sway starts to fade out. */
    #[Property]
    public float $foliageFadeStart = 100.0;

    /** Camera distance (m) beyond which foliage no longer sways. */
    #[Property]
    public float $foliageFadeEnd = 130.0;

    public function __construct(
        float $baseIntensity = 0.5,
        float $gustiness = 0.3,
        float $gustFrequency = 0.2,
    ) {
        $this->baseIntensity  = $baseIntensity;
        $this->gustiness      = $gustiness;
        $this->gustFrequency  = $gustFrequency;
        $this->intensity      = $baseIntensity;
        $this->time           = 0.0;
        $this->minIntensity   = 0.0;
        $this->maxIntensity   = 2.0;
        $this->direction      = new Vec3(1.0, 0.0, 0.0);
    }
}
