<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class ProgressBar extends Widget
{
    public string $label;
    public float $value;

    public function __construct(string $label = '', float $value = 0.0)
    {
        parent::__construct();
        $this->label = $label;
        $this->value = $value;
        $this->padding = EdgeInsets::all(4.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $barH = $style->fontSize * 0.6;
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : 200.0 + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height
                : $labelH + $barH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;
        $barH = $style->fontSize * 0.6;
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;

        if ($this->label !== '') {
            $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
            $renderer->drawText($this->label, $b->x + $this->padding->left, $b->y + $this->padding->top, $style->fontSize, $style->textColor);
        }

        $barX = $b->x + $this->padding->left;
        $barY = $b->y + $this->padding->top + $labelH;
        $barW = $b->width - $this->padding->horizontal();

        $renderer->drawRoundedRect($barX, $barY, $barW, $barH, $barH * 0.5, $style->backgroundColor);

        $fillW = $barW * max(0.0, min(1.0, $this->value));
        if ($fillW > 1.0) {
            $renderer->drawRoundedRect($barX, $barY, $fillW, $barH, $barH * 0.5, $style->accentColor);
        }
    }
}
