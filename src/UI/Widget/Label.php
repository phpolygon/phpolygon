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

    /**
     * Word-wrap the text to the label's width and draw it as multiple lines.
     * Each line is emitted with drawText (per-glyph fallback chain — so non-Latin
     * bodies render correctly, unlike a single drawTextBox call). Honours hard
     * line breaks (\n). Requires a bounded width (fillWidth or an explicit width).
     */
    public bool $wrap = false;

    /** Line advance as a multiple of the font size, used when wrapping. */
    public float $lineHeight = 1.3;

    public function __construct(string $text = '')
    {
        parent::__construct();
        $this->text = $text;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $fs = $this->fontSize ?? $style->fontSize;

        // An empty auto-sized label collapses to zero height so optional lines
        // (warnings, requirements, hints) reserve no space when unset, rather
        // than leaving a blank row that inflates every card.
        if ($this->text === '' && !$this->sizing->fillHeight && $this->sizing->height <= 0.0) {
            $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
                : ($this->sizing->width > 0 ? $this->sizing->width : 0.0);
            $this->measuredHeight = 0.0;

            return;
        }

        // Wrapping label: width is the container's; height grows with line count.
        // Wrapping uses the same char-advance estimate as draw() (below), so the
        // reserved height matches the number of lines actually drawn.
        if ($this->wrap && ($this->sizing->fillWidth || $this->sizing->width > 0.0)) {
            $w = $this->sizing->fillWidth ? $availableWidth : $this->sizing->width;
            $lineCount = max(1, count(self::wrapLines($this->text, $w - $this->padding->horizontal(), $fs)));
            $this->measuredWidth = $w;
            $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
                : $lineCount * $fs * $this->lineHeight + $this->padding->vertical();

            return;
        }

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

        $x = $this->bounds->x + $this->padding->left;
        $y = $this->bounds->y + $this->padding->top;

        if ($this->wrap && $this->bounds->width > 0.0) {
            $lines = self::wrapLines($this->text, $this->bounds->width - $this->padding->horizontal(), $fs);
            $step = $fs * $this->lineHeight;
            foreach ($lines as $line) {
                if ($line !== '') {
                    $renderer->drawText($line, $x, $y, $fs, $color);
                }
                $y += $step;
            }

            return;
        }

        $renderer->drawText($this->text, $x, $y, $fs, $color);
    }

    /**
     * Greedy word-wrap to $maxWidth using the same char-advance estimate the
     * label measures width with (fontSize * 0.62), so measure() and draw() agree
     * on the line count. Hard breaks (\n / \r\n) always start a new line.
     *
     * @return list<string>
     */
    private static function wrapLines(string $text, float $maxWidth, float $fontSize): array
    {
        $charW = max(0.001, $fontSize * 0.62);
        $maxChars = $maxWidth > 0.0 ? (int) max(1, floor($maxWidth / $charW)) : PHP_INT_MAX;

        $lines = [];
        $paragraphs = preg_split('/\r\n?|\n/', $text) ?: [$text];
        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }
            $line = '';
            foreach (explode(' ', $paragraph) as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (mb_strlen($candidate) <= $maxChars || $line === '') {
                    $line = $candidate;
                } else {
                    $lines[] = $line;
                    $line = $word;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
