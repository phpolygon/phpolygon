<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * A room the sun does not reach: inside this box (centred on the entity, turned with
 * its yaw) the mesh shader drops the direct sun and most of the sky reflection, so
 * daylight does not leak through a thin roof or walls the shadow cascades miss. The
 * room's own lamps and the ambient stay. {@see \PHPolygon\System\InteriorVolumeSystem}
 * hands the four nearest to the camera to the shader.
 */
#[Serializable]
#[Category('Lighting')]
class InteriorVolume extends AbstractComponent
{
    #[Property]
    public Vec3 $halfExtents;

    /** 1 = no sun at all inside, 0 = no effect. */
    #[Property]
    #[Range(min: 0, max: 1)]
    public float $strength = 1.0;

    public function __construct(?Vec3 $halfExtents = null, float $strength = 1.0)
    {
        $this->halfExtents = $halfExtents ?? new Vec3(1.0, 1.0, 1.0);
        $this->strength = $strength;
    }
}
