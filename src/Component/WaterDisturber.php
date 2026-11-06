<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Marks an entity that disturbs a water surface: a hull leaves a wake
 * ({@see WAKE}), a swimmer or a splash sends out rings ({@see RIPPLE}).
 * {@see \PHPolygon\System\WaterDisturbanceSystem} follows its Transform3D and
 * hands the trail or the rings to the water shader
 * ({@see \PHPolygon\Rendering\Water\WaterShading}). Whether the entity is in the
 * water at all is the game's call: it sets {@see $active}.
 */
#[Serializable]
#[Category('Rendering')]
class WaterDisturber extends AbstractComponent
{
    public const string WAKE = 'wake';
    public const string RIPPLE = 'ripple';

    /** {@see WAKE} or {@see RIPPLE}. */
    #[Property]
    public string $kind = self::WAKE;

    /** Off: no new trail points or rings; what is on the water fades as usual. */
    #[Property]
    public bool $active = true;

    /** Scales foam and slope, 0..1. */
    #[Property]
    #[Range(0.0, 1.0)]
    public float $strength = 1.0;

    /** Speed (m/s) at which a wake reaches full strength; slower hulls leave a fainter one. */
    #[Property]
    #[Range(0.1, 50.0)]
    public float $fullSpeed = 6.0;

    /** Metres between trail points of a wake, or between rings of a moving swimmer. */
    #[Property]
    #[Range(0.5, 20.0)]
    public float $spacing = 4.0;

    /** Seconds between rings while a {@see RIPPLE} source stays in place (0 = only when moving). */
    #[Property]
    #[Range(0.0, 10.0)]
    public float $interval = 1.2;

    public function __construct(
        string $kind = self::WAKE,
        bool $active = true,
        float $strength = 1.0,
        float $fullSpeed = 6.0,
        float $spacing = 4.0,
        float $interval = 1.2,
    ) {
        if ($kind !== self::WAKE && $kind !== self::RIPPLE) {
            throw new \InvalidArgumentException("Unknown water disturbance '{$kind}'");
        }
        $this->kind = $kind;
        $this->active = $active;
        $this->strength = $strength;
        $this->fullSpeed = $fullSpeed;
        $this->spacing = $spacing;
        $this->interval = $interval;
    }
}
