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
    #[Property(editorHint: 'asset:mesh')]
    public string $meshId;

    #[Property(editorHint: 'asset:material')]
    public string $materialId;

    #[Property]
    public bool $castShadows;

    /**
     * When false, Renderer3DSystem skips this mesh — used for blink/hide effects.
     * Inline default so {@see \PHPolygon\ECS\Serializer\AttributeSerializer::fromArray}
     * (which constructs via newInstanceWithoutConstructor()) leaves the typed
     * property in a defined state for legacy data that predates this field.
     */
    #[Property]
    public bool $visible = true;

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

    public function __construct(
        string $meshId = '',
        string $materialId = '',
        bool $castShadows = true,
        bool $visible = true,
        bool $excludeFromGbuffer = false,
    ) {
        $this->meshId = $meshId;
        $this->materialId = $materialId;
        $this->castShadows = $castShadows;
        $this->visible = $visible;
        $this->excludeFromGbuffer = $excludeFromGbuffer;
    }
}
