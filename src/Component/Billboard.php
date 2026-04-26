<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

enum BillboardMode
{
    /** Full billboard - always faces camera on all axes. */
    case Full;

    /** Cylindrical - rotates around Y axis only (upright sprites). */
    case AxisY;
}

/**
 * Makes an entity's mesh always face the active camera.
 *
 * Attach alongside a MeshRenderer + Transform3D. The BillboardSystem
 * overrides the entity's rotation each frame to face the camera.
 */
#[Serializable]
#[Category('Rendering')]
class Billboard extends AbstractComponent
{
    #[Property]
    public BillboardMode $mode;

    public function __construct(
        BillboardMode $mode = BillboardMode::Full,
    ) {
        $this->mode = $mode;
    }
}
