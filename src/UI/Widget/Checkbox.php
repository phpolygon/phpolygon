<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class Checkbox extends Widget
{
    public string $label;
    public bool $checked;

    public function __construct(string $label = '', bool $checked = false)
    {
        parent::__construct();
        $this->label = $label;
        $this->checked = $checked;
        $this->padding = EdgeInsets::all(4.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $boxSize = $style->fontSize;
        $textW = mb_strlen($this->label) * $style->fontSize * 0.55;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width
                : $boxSize + 6.0 + $textW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height
                : $boxSize + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;
        $boxSize = $style->fontSize;
        $boxX = $b->x + $this->padding->left;
        $boxY = $b->y + $this->padding->top;

        $renderer->drawRoundedRect($boxX, $boxY, $boxSize, $boxSize, $style->borderRadius * 0.5, $style->backgroundColor);
        $renderer->drawRectOutline($boxX, $boxY, $boxSize, $boxSize, $style->borderColor, $style->borderWidth);

        if ($this->checked) {
            $inset = $boxSize * 0.25;
            $renderer->drawRoundedRect(
                $boxX + $inset, $boxY + $inset,
                $boxSize - $inset * 2, $boxSize - $inset * 2,
                2.0, $style->accentColor,
            );
        }

        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $renderer->drawText(
            $this->label,
            $boxX + $boxSize + 6.0,
            $boxY,
            $style->fontSize,
            $style->textColor,
        );
    }
}
