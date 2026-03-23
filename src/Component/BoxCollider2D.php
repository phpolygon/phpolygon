<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;

#[Serializable]
#[Category('Physics')]
class BoxCollider2D extends AbstractComponent
{
    #[Property(editorHint: 'vec2')]
    public Vec2 $offset;

    #[Property(editorHint: 'vec2')]
    public Vec2 $size;

    #[Property]
    public bool $isTrigger;

    public function __construct(
        ?Vec2 $size = null,
        ?Vec2 $offset = null,
        bool $isTrigger = false,
    ) {
        $this->size = $size ?? new Vec2(32.0, 32.0);
        $this->offset = $offset ?? Vec2::zero();
        $this->isTrigger = $isTrigger;
    }

    public function getWorldRect(Vec2 $entityPosition): Rect
    {
        return new Rect(
            $entityPosition->x + $this->offset->x - $this->size->x * 0.5,
            $entityPosition->y + $this->offset->y - $this->size->y * 0.5,
            $this->size->x,
            $this->size->y,
        );
    }
}
