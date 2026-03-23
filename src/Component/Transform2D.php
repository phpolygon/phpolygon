<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Mat3;
use PHPolygon\Math\Vec2;

#[Serializable]
class Transform2D extends AbstractComponent
{
    #[Property(editorHint: 'vec2')]
    public Vec2 $position;

    #[Property(editorHint: 'angle')]
    #[Range(min: 0, max: 360)]
    public float $rotation;

    #[Property(editorHint: 'vec2')]
    public Vec2 $scale;

    #[Hidden]
    public ?int $parentEntityId = null;

    public function __construct(
        ?Vec2 $position = null,
        float $rotation = 0.0,
        ?Vec2 $scale = null,
        ?int $parentEntityId = null,
    ) {
        $this->position = $position ?? Vec2::zero();
        $this->rotation = $rotation;
        $this->scale = $scale ?? Vec2::one();
        $this->parentEntityId = $parentEntityId;
    }

    public function getLocalMatrix(): Mat3
    {
        return Mat3::trs($this->position, deg2rad($this->rotation), $this->scale);
    }
}
