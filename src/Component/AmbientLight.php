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
 * Global ambient fill light. Unlike DirectionalLight / PointLight it has no
 * position or direction; the Renderer3DSystem turns each AmbientLight into a
 * SetAmbientLight command. A scene normally has one. (A three.js HemisphereLight
 * is imported as an AmbientLight with its sky/ground colours blended, since the
 * forward renderer has no hemisphere gradient model.)
 */
#[Serializable]
#[Category('Lighting')]
class AmbientLight extends AbstractComponent
{
    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 4)]
    public float $intensity;

    public function __construct(?Color $color = null, float $intensity = 1.0)
    {
        $this->color = $color ?? Color::white();
        $this->intensity = $intensity;
    }
}
