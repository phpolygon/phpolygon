<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

#[Serializable]
#[Category('Core')]
class Transform3D extends AbstractComponent
{
    #[Property(editorHint: 'vec3')]
    public Vec3 $position;

    #[Property(editorHint: 'quaternion')]
    public Quaternion $rotation;

    #[Property(editorHint: 'vec3')]
    public Vec3 $scale;

    #[Property]
    public ?int $parentEntityId = null;

    /** @var list<int> */
    #[Property]
    public array $childEntityIds = [];

    #[Hidden]
    public Mat4 $worldMatrix;

    public function __construct(
        ?Vec3 $position = null,
        ?Quaternion $rotation = null,
        ?Vec3 $scale = null,
        ?int $parentEntityId = null,
    ) {
        $this->position = $position ?? Vec3::zero();
        $this->rotation = $rotation ?? Quaternion::identity();
        $this->scale = $scale ?? Vec3::one();
        $this->parentEntityId = $parentEntityId;
        $this->worldMatrix = $this->getLocalMatrix();
    }

    public function getLocalMatrix(): Mat4
    {
        return Mat4::trs($this->position, $this->rotation, $this->scale);
    }

    public function getWorldMatrix(): Mat4
    {
        return $this->worldMatrix;
    }

    public function getWorldPosition(): Vec3
    {
        return $this->worldMatrix->getTranslation();
    }

    /**
     * Attach a child entity to this transform's hierarchy.
     * Sets the child's parentEntityId and adds it to this transform's childEntityIds.
     */
    public function addChild(Transform3D $child, int $childEntityId, int $parentEntityId): void
    {
        $child->parentEntityId = $parentEntityId;
        if (!in_array($childEntityId, $this->childEntityIds, true)) {
            $this->childEntityIds[] = $childEntityId;
        }
    }

    /**
     * Remove a child entity from this transform's hierarchy.
     */
    public function removeChild(Transform3D $child, int $childEntityId): void
    {
        $child->parentEntityId = null;
        $this->childEntityIds = array_values(
            array_filter($this->childEntityIds, fn(int $id) => $id !== $childEntityId)
        );
    }
}
