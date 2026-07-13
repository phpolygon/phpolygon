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

    /**
     * Horizontal label alignment: 'center' (default, for action buttons),
     * 'left' or 'right'. A full-width button used as a list row wants 'left' so
     * its text reads like a row rather than a centered caption.
     */
    public string $align = 'center';

    /**
     * When true the button draws no background/fill — only its label (if any).
     * Use for an invisible click target that covers a card: layer a flat,
     * fill-sized button over the card content so clicking anywhere on the card
     * fires the button, while the content (Labels, which are transparent to
     * hit-testing) shows through and stays visible.
     */
    public bool $flat = false;

    public function __construct(string $label = '')
    {
        parent::__construct();
        $this->label = $label;
        $this->padding = EdgeInsets::symmetric(horizontal: 12.0, vertical: 6.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        // 0.62 avg glyph-advance / font-size for the semibold UI font. The old
        // 0.55 under-measured, so auto-sized buttons were narrower than their
        // (often longer, German) labels and clipped the text.
        $textW = mb_strlen($this->label) * $style->fontSize * 0.62;
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

        if (!$this->flat) {
            $bg = !$this->enabled ? $style->disabledColor
                : ($this->pressed ? $style->activeColor
                    : ($this->hovered ? $style->backgroundColor : $style->hoverColor));

            $renderer->drawRoundedRect($b->x, $b->y, $b->width, $b->height, $style->borderRadius, $bg);
        }

        if ($this->label === '') {
            return;
        }

        $textColor = $this->enabled ? $style->textColor : $style->textColor->withAlpha(0.5);
        // Label is vertically centered; horizontal anchor follows $align. Set the
        // alignment explicitly — the renderer's text align is sticky global
        // state, so a sibling widget (or a prior immediate-mode draw) must not be
        // able to leave it elsewhere.
        [$hAlign, $tx] = match ($this->align) {
            'left'  => [TextAlign::LEFT,  $b->x + $this->padding->left],
            'right' => [TextAlign::RIGHT, $b->x + $b->width - $this->padding->right],
            default => [TextAlign::CENTER, $b->x + $b->width / 2.0],
        };
        $renderer->setTextAlign($hAlign | TextAlign::MIDDLE);
        $renderer->drawText(
            $this->label,
            $tx,
            $b->y + $b->height / 2.0,
            $style->fontSize,
            $textColor,
        );
    }
}
