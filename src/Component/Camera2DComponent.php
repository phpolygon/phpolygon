<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Rect;

#[Serializable]
class Camera2DComponent extends AbstractComponent
{
    #[Property(editorHint: 'slider')]
    #[Range(min: 0.1, max: 10)]
    public float $zoom;

    #[Property(editorHint: 'rect')]
    public ?Rect $bounds;

    #[Property]
    public bool $active;

    public function __construct(
        float $zoom = 1.0,
        ?Rect $bounds = null,
        bool $active = true,
    ) {
        $this->zoom = $zoom;
        $this->bounds = $bounds;
        $this->active = $active;
    }
}
