<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\Color;

/**
 * Screen-space outline around an entity's mesh (and, by default, the meshes of
 * its Transform3D children). {@see \PHPolygon\System\OutlineSystem} turns it
 * into {@see \PHPolygon\Rendering\Command\DrawOutline} commands; the renderer
 * draws them with a stencil mask after the scene.
 */
#[Serializable]
#[Category('Rendering')]
class Outline extends AbstractComponent
{
    #[Property]
    public bool $enabled = true;

    #[Property]
    public Color $color;

    /** Ring width in screen pixels, independent of distance. */
    #[Property]
    #[Range(0.5, 16.0)]
    public float $widthPx = 3.0;

    /** Also outline the MeshRenderers of all Transform3D descendants. */
    #[Property]
    public bool $includeChildren = true;

    public function __construct(
        bool $enabled = true,
        ?Color $color = null,
        float $widthPx = 3.0,
        bool $includeChildren = true,
    ) {
        $this->enabled = $enabled;
        $this->color = $color ?? new Color(1.0, 0.85, 0.3);
        $this->widthPx = $widthPx;
        $this->includeChildren = $includeChildren;
    }
}
