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
 * A rectangular area light: a light panel, a light band, a lit window. The rectangle
 * lies in the entity's local XZ plane ({@see $width} along local X, {@see $depth}
 * along local Z) around the {@see Transform3D} position and emits along local -Y
 * (no rotation = a ceiling panel shining down); the world rotation of the entity
 * turns it. One-sided.
 *
 * The Renderer3DSystem emits an {@see \PHPolygon\Rendering\Command\AddRectLight};
 * the mesh shader lights with the exact Lambert form factor of the rectangle
 * (clipped at the receiver's horizon) and a representative-point specular, so
 * a long band reads long and only its front is lit. Brightness follows the
 * engine's point lights (windowed by {@see $range}, no inverse square): from far
 * away the panel lights like a {@see PointLight} of the same {@see $intensity},
 * times the cosine of the panel's own facing.
 */
#[Serializable]
#[Category('Lighting')]
class RectAreaLight extends AbstractComponent
{
    #[Property]
    public float $width;

    #[Property]
    public float $depth;

    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 50)]
    public float $intensity;

    #[Property]
    public float $range;

    public function __construct(
        float $width = 1.0,
        float $depth = 1.0,
        ?Color $color = null,
        float $intensity = 4.0,
        float $range = 12.0,
    ) {
        $this->width = $width;
        $this->depth = $depth;
        $this->color = $color ?? Color::white();
        $this->intensity = $intensity;
        $this->range = $range;
    }
}
