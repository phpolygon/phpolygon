<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class Slider extends Widget
{
    public string $label;
    public float $value;
    public float $min;
    public float $max;
    public bool $dragging = false;

    public function __construct(string $label = '', float $value = 0.0, float $min = 0.0, float $max = 1.0)
    {
        parent::__construct();
        $this->label = $label;
        $this->value = $value;
        $this->min = $min;
        $this->max = $max;
        $this->padding = EdgeInsets::symmetric(horizontal: 4.0, vertical: 4.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $barH = $style->fontSize * 0.5;
        $labelH = $style->fontSize;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : 200.0 + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height
                : $labelH + 4.0 + $barH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;
        $barH = $style->fontSize * 0.5;

        // Label + value
        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $renderer->drawText(
            sprintf('%s: %.2f', $this->label, $this->value),
            $b->x + $this->padding->left,
            $b->y + $this->padding->top,
            $style->fontSize,
            $style->textColor,
        );

        $barX = $b->x + $this->padding->left;
        $barY = $b->y + $this->padding->top + $style->fontSize + 4.0;
        $barW = $b->width - $this->padding->horizontal();

        // Track
        $renderer->drawRoundedRect($barX, $barY, $barW, $barH, $barH * 0.5, $style->backgroundColor);

        // Fill
        $t = ($this->max > $this->min) ? ($this->value - $this->min) / ($this->max - $this->min) : 0.0;
        $fillW = $barW * max(0.0, min(1.0, $t));
        if ($fillW > 1.0) {
            $renderer->drawRoundedRect($barX, $barY, $fillW, $barH, $barH * 0.5, $style->accentColor);
        }

        // Thumb
        $thumbX = $barX + $fillW;
        $thumbY = $barY + $barH * 0.5;
        $renderer->drawCircle($thumbX, $thumbY, $barH, $style->accentColor);
    }

    /**
     * Calculate value from an X mouse position.
     */
    public function valueFromMouseX(float $mouseX): float
    {
        $barX = $this->bounds->x + $this->padding->left;
        $barW = $this->bounds->width - $this->padding->horizontal();
        $t = max(0.0, min(1.0, ($mouseX - $barX) / $barW));
        return $this->min + ($this->max - $this->min) * $t;
    }
}
