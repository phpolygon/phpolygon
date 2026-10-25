<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Grass blades bend away from this entity (its Transform3D world position):
 * within `$radius` metres they lean outward, laid down most at the centre.
 * {@see \PHPolygon\System\GrassSystem} sends the benders nearest to the camera
 * (at most {@see \PHPolygon\Rendering\Command\SetGrass::MAX_BENDERS}) to the
 * shader; it costs nothing on the CPU per blade.
 */
#[Serializable]
#[Category('Rendering')]
class GrassBender extends AbstractComponent
{
    #[Property]
    public float $radius = 0.7;

    #[Property]
    public bool $enabled = true;

    public function __construct(float $radius = 0.7)
    {
        $this->radius = $radius;
    }
}
