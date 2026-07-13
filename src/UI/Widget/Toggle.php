<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class Toggle extends Widget
{
    public string $label;
    public bool $on;

    public function __construct(string $label = '', bool $on = false)
    {
        parent::__construct();
        $this->label = $label;
        $this->on = $on;
        $this->padding = EdgeInsets::all(4.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $trackW = $style->fontSize * 2.2;
        $trackH = $style->fontSize * 1.2;
        $textW = mb_strlen($this->label) * $style->fontSize * 0.55;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width
                : $trackW + 8.0 + $textW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height
                : $trackH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;
        $trackW = $style->fontSize * 2.2;
        $trackH = $style->fontSize * 1.2;
        $trackX = $b->x + $this->padding->left;
        $trackY = $b->y + $this->padding->top;
        $radius = $trackH * 0.5;

        $trackColor = $this->on ? $style->accentColor : $style->backgroundColor;
        $renderer->drawRoundedRect($trackX, $trackY, $trackW, $trackH, $radius, $trackColor);
        $renderer->drawRectOutline($trackX, $trackY, $trackW, $trackH, $style->borderColor, $style->borderWidth);

        // Thumb
        $thumbR = $radius - 3.0;
        $thumbX = $this->on
            ? $trackX + $trackW - $radius
            : $trackX + $radius;
        $thumbY = $trackY + $trackH * 0.5;
        $renderer->drawCircle($thumbX, $thumbY, $thumbR, Color::white());

        // Label
        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $renderer->drawText(
            $this->label,
            $trackX + $trackW + 8.0,
            $trackY + ($trackH - $style->fontSize) * 0.5,
            $style->fontSize,
            $style->textColor,
        );
    }
}
