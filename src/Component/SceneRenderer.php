<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\RenderLayer;

/**
 * Tags an entity to participate in layer-based compositing.
 *
 * The CompositingSystem collects all SceneRenderer components,
 * groups them by layer, and renders each layer in order.
 * Depth buffer can be cleared between layers so that HUD
 * elements are never occluded by 3D geometry.
 */
#[Serializable]
#[Category('Rendering')]
class SceneRenderer extends AbstractComponent
{
    /** Which render layer this entity belongs to. */
    #[Property]
    public RenderLayer $renderLayer;

    /** Whether to clear the depth buffer before this entity's layer renders. */
    #[Property]
    public bool $clearDepth;

    /** Whether this renderer is enabled. */
    #[Property]
    public bool $enabled;

    /** Sort order within the same layer (lower = earlier). */
    #[Property]
    public int $sortOrder;

    public function __construct(
        RenderLayer $renderLayer = RenderLayer::World3D,
        bool $clearDepth = false,
        bool $enabled = true,
        int $sortOrder = 0,
    ) {
        $this->renderLayer = $renderLayer;
        $this->clearDepth = $clearDepth;
        $this->enabled = $enabled;
        $this->sortOrder = $sortOrder;
    }
}
