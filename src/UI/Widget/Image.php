<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\Texture;
use PHPolygon\UI\UIStyle;

class Image extends Widget
{
    public ?Texture $texture = null;
    public float $opacity = 1.0;

    public function __construct(?Texture $texture = null, float $width = 0.0, float $height = 0.0)
    {
        parent::__construct();
        $this->texture = $texture;
        if ($width > 0 || $height > 0) {
            $this->sizing = Sizing::fixed($width, $height);
        }
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $texW = $this->texture !== null ? $this->texture->width : 0;
        $texH = $this->texture !== null ? $this->texture->height : 0;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : (float) $texW);
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : (float) $texH);
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        if ($this->texture === null) return;

        $b = $this->bounds;
        $renderer->drawSprite($this->texture, null, $b->x, $b->y, $b->width, $b->height, $this->opacity);
    }
}
