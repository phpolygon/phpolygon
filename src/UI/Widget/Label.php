<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class Label extends Widget
{
    public string $text;
    public ?Color $color = null;
    public ?float $fontSize = null;

    public function __construct(string $text = '')
    {
        parent::__construct();
        $this->text = $text;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $fs = $this->fontSize ?? $style->fontSize;

        // Approximate text width: chars * fontSize * 0.62 (avg glyph advance for
        // the UI font; 0.55 under-measured and clipped auto-sized text).
        $textW = mb_strlen($this->text) * $fs * 0.62;
        $textH = $fs;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : $textW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $textH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void
    {
        // Leaf widget — nothing to lay out
    }

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $fs = $this->fontSize ?? $style->fontSize;
        $color = $this->color ?? $style->textColor;

        // Explicit left/top anchor: the renderer's text align is sticky global
        // state, so without this a Label after a centered widget would inherit
        // CENTER|MIDDLE and render offset from its top-left origin.
        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $renderer->drawText(
            $this->text,
            $this->bounds->x + $this->padding->left,
            $this->bounds->y + $this->padding->top,
            $fs,
            $color,
        );
    }
}
