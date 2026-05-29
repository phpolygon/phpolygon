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

    public function __construct(
        string $meshId = '',
        string $materialId = '',
        bool $castShadows = true,
        bool $visible = true,
    ) {
        $this->meshId = $meshId;
        $this->materialId = $materialId;
        $this->castShadows = $castShadows;
        $this->visible = $visible;
    }
}
