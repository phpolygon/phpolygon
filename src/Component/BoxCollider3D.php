<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Axis-aligned 3D box collider.
 * Size is the full extent (not half-extent). Centered on the entity's position + offset.
 */
#[Serializable]
#[Category('Physics')]
class BoxCollider3D extends AbstractComponent
{
    #[Property(editorHint: 'vec3')]
    public Vec3 $size;

    #[Property(editorHint: 'vec3')]
    public Vec3 $offset;

    #[Property]
    public bool $isTrigger;

    #[Property]
    public bool $isStatic;

    public function __construct(
        ?Vec3 $size = null,
        ?Vec3 $offset = null,
        bool $isTrigger = false,
        bool $isStatic = true,
    ) {
        $this->size = $size ?? new Vec3(1.0, 1.0, 1.0);
        $this->offset = $offset ?? Vec3::zero();
        $this->isTrigger = $isTrigger;
        $this->isStatic = $isStatic;
    }

    /**
     * Get the world-space AABB min/max from entity position.
     *
     * @return array{min: Vec3, max: Vec3}
     */
    public function getWorldAABB(Vec3 $entityPosition): array
    {
        $center = $entityPosition->add($this->offset);
        $halfSize = new Vec3(
            $this->size->x * 0.5,
            $this->size->y * 0.5,
            $this->size->z * 0.5,
        );

        return [
            'min' => $center->sub($halfSize),
            'max' => $center->add($halfSize),
        ];
    }
}
