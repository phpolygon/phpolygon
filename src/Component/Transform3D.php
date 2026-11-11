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
    /**
     * Change notification for {@see \PHPolygon\System\Transform3DSystem}: set
     * on bind (World::observe's attach callback, see bindDirtyQueue()) so the
     * hooks below can mark this entity dirty without the system walking the
     * whole Transform3D pool every tick. Null while unattached (construction,
     * hydration, a cloned-but-not-yet-attached prefab component) - the hooks
     * just no-op then, since the system reprocesses every newly attached
     * Transform3D once anyway.
     */
    private ?TransformDirtyQueue $dirtyQueue = null;

    /** Entity id this instance is bound to; meaningless while $dirtyQueue is null. */
    private int $boundEntityId = 0;

    #[Property(editorHint: 'vec3')]
    public Vec3 $position {
        set {
            $this->position = $value;
            $this->notifyDirty();
        }
    }

    #[Property(editorHint: 'quaternion')]
    public Quaternion $rotation {
        set {
            $this->rotation = $value;
            $this->notifyDirty();
        }
    }

    #[Property(editorHint: 'vec3')]
    public Vec3 $scale {
        set {
            $this->scale = $value;
            $this->notifyDirty();
        }
    }

    #[Property]
    public ?int $parentEntityId = null {
        set {
            $this->parentEntityId = $value;
            $this->notifyDirty();
        }
    }

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

    /**
     * Rebinding after a clone (prefab instancing clones authored components
     * before attaching them, see SceneBuilder::materializeDeclaration()):
     * the clone is not attached to anything yet, so it must start unbound -
     * otherwise it would mark the ORIGINAL's entity id dirty on its first
     * write, and World::observe's attach callback would never get a chance
     * to bind it to its own id.
     */
    public function __clone(): void
    {
        $this->dirtyQueue = null;
        $this->boundEntityId = 0;
    }

    /**
     * Bind this instance to its entity id and the owning Transform3DSystem's
     * dirty queue. Called from the attach callback registered through
     * {@see \PHPolygon\ECS\World::observe()} - never call this directly.
     */
    public function bindDirtyQueue(int $entityId, TransformDirtyQueue $queue): void
    {
        $this->boundEntityId = $entityId;
        $this->dirtyQueue = $queue;
    }

    /** Called from the matching detach callback - never call this directly. */
    public function unbindDirtyQueue(): void
    {
        $this->dirtyQueue = null;
    }

    /**
     * Marks this transform dirty without assigning any property. Game code
     * that mutates position/rotation/scale IN PLACE (`$t->position->x = …`
     * - Vec3/Quaternion are mutable, so that write never runs the hooks
     * above) must call this afterwards, or rely on Transform3DSystem's
     * safety net to catch it within a handful of ticks.
     */
    public function touch(): void
    {
        $this->notifyDirty();
    }

    private function notifyDirty(): void
    {
        $this->dirtyQueue?->mark($this->boundEntityId);
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
