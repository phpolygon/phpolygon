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

/**
 * A local body of fog, haze or smog: a box of {@see $halfExtents} around the entity's
 * {@see Transform3D} (turned by its yaw), {@see $density} per metre inside, fading
 * out over the outer {@see $edge} fraction of each half extent. Its {@see $color}
 * (linear) is lit by the ambient and sun light. The four volumes nearest to the
 * camera go to the mesh shader ({@see \PHPolygon\System\AtmosphereFogSystem}),
 * which integrates the length of every view ray inside them.
 */
#[Serializable]
#[Category('Rendering')]
class FogVolume extends AbstractComponent
{
    #[Property(editorHint: 'vec3')]
    public Vec3 $halfExtents;

    #[Property]
    #[Range(min: 0, max: 0.5)]
    public float $density;

    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property]
    #[Range(min: 0.01, max: 1)]
    public float $edge;

    public function __construct(?Vec3 $halfExtents = null, float $density = 0.02, ?Color $color = null, float $edge = 0.3)
    {
        $this->halfExtents = $halfExtents ?? new Vec3(10.0, 5.0, 10.0);
        $this->density = $density;
        $this->color = $color ?? new Color(0.7, 0.72, 0.75);
        $this->edge = $edge;
    }
}
