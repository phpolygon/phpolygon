<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\Color;

/**
 * Exponential height fog for the whole scene: density {@see $density} (per metre)
 * at {@see $baseHeight}, thinning by e per 1/{@see $falloff} metres upward; none
 * below the base (put it at the sea level: the underwater view keeps its own look).
 * Valleys and the sea surface lie in it, hill tops and towers rise out. Integrated analytically along every view ray by the mesh shader
 * ({@see \PHPolygon\System\AtmosphereFogSystem}). {@see $color} null = the
 * scene's distance-fog colour; otherwise a linear colour lit by the ambient and
 * sun light.
 */
#[Serializable]
#[Category('Rendering')]
class HeightFog extends AbstractComponent
{
    #[Property]
    #[Range(min: 0, max: 0.2)]
    public float $density;

    #[Property]
    #[Range(min: 0.001, max: 2)]
    public float $falloff;

    #[Property]
    public float $baseHeight;

    #[Property(editorHint: 'color')]
    public ?Color $color;

    public function __construct(float $density = 0.0, float $falloff = 0.1, float $baseHeight = 0.0, ?Color $color = null)
    {
        $this->density = $density;
        $this->falloff = $falloff;
        $this->baseHeight = $baseHeight;
        $this->color = $color;
    }
}
