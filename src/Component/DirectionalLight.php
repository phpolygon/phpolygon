<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

#[Serializable]
#[Category('Lighting')]
class DirectionalLight extends AbstractComponent
{
    #[Property(editorHint: 'vec3')]
    public Vec3 $direction;

    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 10)]
    public float $intensity;

    /**
     * How strongly this light scatters in the volumetric fog, 0..1 (only the
     * primary light scatters). 1 = as its intensity says; a night light that
     * must stay bright enough to see by (moonlight) sets it low, or it would
     * wash the view out like the sun.
     */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $volumetricScale;

    public function __construct(
        ?Vec3 $direction = null,
        ?Color $color = null,
        float $intensity = 1.0,
        float $volumetricScale = 1.0,
    ) {
        $this->direction = $direction ?? new Vec3(0.0, -1.0, 0.0);
        $this->color = $color ?? Color::white();
        $this->intensity = $intensity;
        $this->volumetricScale = $volumetricScale;
    }
}
