<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
#[Category('Rendering')]
class MeshRenderer extends AbstractComponent
{
    /**
     * Change notification for {@see \PHPolygon\Visibility\VisibilityService}:
     * set on bind (World::observe's attach callback, see bindChangeQueue())
     * so the $visible/$meshId hooks below can mark this entity without the
     * service walking the whole MeshRenderer pool every frame. Null while
     * unattached (construction, hydration, a cloned-but-not-yet-attached
     * prefab component) - the hooks just no-op then, since the service
     * registers every newly attached MeshRenderer once anyway.
     */
    private ?MeshRendererChangeQueue $changeQueue = null;

    /** Entity id this instance is bound to; meaningless while $changeQueue is null. */
    private int $boundEntityId = 0;

    #[Property(editorHint: 'asset:mesh')]
    public string $meshId {
        set {
            $this->meshId = $value;
            $this->notifyChanged();
        }
    }

    #[Property(editorHint: 'asset:material')]
    public string $materialId;

    #[Property]
    public bool $castShadows;

    /**
     * When false, Renderer3DSystem skips this mesh — used for blink/hide effects.
     * Inline default so {@see \PHPolygon\ECS\Serializer\AttributeSerializer::fromArray}
     * (which constructs via newInstanceWithoutConstructor()) leaves the typed
     * property in a defined state for legacy data that predates this field.
     *
     * Toggling this is also one of VisibilityService's three maintenance
     * events for its SpatialIndex: the entry is removed while false and
     * re-registered when set back to true, so an invisible mesh never costs
     * a cell membership or classification.
     */
    #[Property]
    public bool $visible = true {
        set {
            $this->visible = $value;
            $this->notifyChanged();
        }
    }

    /**
     * When true, this mesh is NOT written into the deferred G-buffer that drives
     * the screen-space effects (SSAO, fieldtracing SDF-AO, SSR). Use for DYNAMIC
     * objects that move outside the baked SDF/probe data: the baked field can't
     * represent them, so sampling it on their surface yields voxel-grid mottle.
     * Excluded fragments read the background of the AO maps (sky → 1.0) and so
     * get no spurious screen-space occlusion. They still render normally in the
     * forward pass; they merely forgo (and don't contribute to) SSAO/SSR.
     */
    #[Property]
    public bool $excludeFromGbuffer = false;

    /**
     * When true, Renderer3DSystem merges this draw with every other visible
     * batchable draw of the same mesh and material into one instanced draw after
     * culling. For meshes that repeat with the same material and do not move;
     * an entity that moved this frame and transparent materials stay single.
     */
    #[Property]
    public bool $batchable = false;

    /**
     * Three floats for `v_instance_params` of this entity's draw, whether it draws
     * alone or in a batch. Runtime state (a stage, a random value): set by a system, not saved.
     *
     * @var list<float>|null
     */
    public ?array $shaderParams = null;

    public function __construct(
        string $meshId = '',
        string $materialId = '',
        bool $castShadows = true,
        bool $visible = true,
        bool $excludeFromGbuffer = false,
        bool $batchable = false,
    ) {
        $this->meshId = $meshId;
        $this->materialId = $materialId;
        $this->castShadows = $castShadows;
        $this->visible = $visible;
        $this->excludeFromGbuffer = $excludeFromGbuffer;
        $this->batchable = $batchable;
    }

    /**
     * Rebinding after a clone (prefab instancing clones authored components
     * before attaching them, see SceneBuilder::materializeDeclaration()):
     * the clone is not attached to anything yet, so it must start unbound -
     * otherwise it would mark the ORIGINAL's entity id changed on its first
     * write, and World::observe's attach callback would never get a chance
     * to bind it to its own id. Mirrors Transform3D::__clone().
     */
    public function __clone(): void
    {
        $this->changeQueue = null;
        $this->boundEntityId = 0;
    }

    /**
     * Bind this instance to its entity id and VisibilityService's change
     * queue. Called from the attach callback registered through
     * {@see \PHPolygon\ECS\World::observe()} - never call this directly.
     */
    public function bindChangeQueue(int $entityId, MeshRendererChangeQueue $queue): void
    {
        $this->boundEntityId = $entityId;
        $this->changeQueue = $queue;
    }

    /** Called from the matching detach callback - never call this directly. */
    public function unbindChangeQueue(): void
    {
        $this->changeQueue = null;
    }

    private function notifyChanged(): void
    {
        $this->changeQueue?->mark($this->boundEntityId);
    }
}
