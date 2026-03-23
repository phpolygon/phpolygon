<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Color;

#[Serializable]
#[Category('Rendering')]
class SpriteRenderer extends AbstractComponent
{
    #[Property(editorHint: 'asset:texture')]
    public string $textureId;

    #[Property(editorHint: 'rect')]
    public ?Rect $region;

    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property(editorHint: 'int')]
    public int $layer;

    #[Property]
    public bool $flipX;

    #[Property]
    public bool $flipY;

    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $opacity;

    #[Property]
    public int $width;

    #[Property]
    public int $height;

    public function __construct(
        string $textureId = '',
        ?Rect $region = null,
        ?Color $color = null,
        int $layer = 0,
        bool $flipX = false,
        bool $flipY = false,
        float $opacity = 1.0,
        int $width = 0,
        int $height = 0,
    ) {
        $this->textureId = $textureId;
        $this->region = $region;
        $this->color = $color ?? Color::white();
        $this->layer = $layer;
        $this->flipX = $flipX;
        $this->flipY = $flipY;
        $this->opacity = $opacity;
        $this->width = $width;
        $this->height = $height;
    }
}
