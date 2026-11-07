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
 * How old the surfaces in a box look (shader plan 5.2): at {@see $age} 0 they are as
 * authored, towards 1 dust settles on top, rain streaks run down walls, metal
 * rusts (copper turns green), moss grows on the north side and bricks bloom
 * white. The box of {@see $halfExtents} around the entity's {@see Transform3D}
 * (turned by its yaw) fades out over its outer {@see $edge} fraction. Materials
 * scale it by {@see \PHPolygon\Rendering\Material::$weathering}. The eight
 * volumes nearest to the camera reach the shader ({@see \PHPolygon\System\AgingSystem}).
 */
#[Serializable]
#[Category('Rendering')]
class AgingVolume extends AbstractComponent
{
    #[Property(editorHint: 'vec3')]
    public Vec3 $halfExtents;

    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $age;

    #[Property]
    #[Range(min: 0.01, max: 1)]
    public float $edge;

    public function __construct(?Vec3 $halfExtents = null, float $age = 0.5, float $edge = 0.15)
    {
        $this->halfExtents = $halfExtents ?? new Vec3(50.0, 50.0, 50.0);
        $this->age = $age;
        $this->edge = $edge;
    }
}
