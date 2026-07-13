<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class Button extends Widget
{
    public string $label;
    public bool $hovered = false;
    public bool $pressed = false;

    public function __construct(string $label = '')
    {
        parent::__construct();
        $this->label = $label;
        $this->padding = EdgeInsets::symmetric(horizontal: 12.0, vertical: 6.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $textW = mb_strlen($this->label) * $style->fontSize * 0.55;
        $textH = $style->fontSize;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : $textW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $textH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;

        $bg = !$this->enabled ? $style->disabledColor
            : ($this->pressed ? $style->activeColor
                : ($this->hovered ? $style->backgroundColor : $style->hoverColor));

        $renderer->drawRoundedRect($b->x, $b->y, $b->width, $b->height, $style->borderRadius, $bg);

        $textColor = $this->enabled ? $style->textColor : $style->textColor->withAlpha(0.5);
        // A button label is centered in its box. Set the alignment explicitly —
        // the renderer's text align is sticky global state, so a sibling widget
        // (or prior immediate-mode draw) must not be able to leave it elsewhere.
        $renderer->setTextAlign(TextAlign::CENTER | TextAlign::MIDDLE);
        $renderer->drawText(
            $this->label,
            $b->x + $b->width / 2.0,
            $b->y + $b->height / 2.0,
            $style->fontSize,
            $textColor,
        );
    }
}
