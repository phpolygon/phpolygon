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
     * Optional filled rounded background drawn behind the text — turns a Label
     * into a badge/pill/tag. Nothing is drawn when null or when the text is
     * empty (so a data-bound badge simply vanishes at zero). Combine with
     * padding for breathing room and align/sizing to shape the pill.
     */
    public ?Color $backgroundColor = null;
    public float $backgroundRadius = 4.0;

    /**
     * Word-wrap the text to the label's width and draw it as multiple lines.
     * Each line is emitted with drawText (per-glyph fallback chain — so non-Latin
     * bodies render correctly, unlike a single drawTextBox call). Honours hard
     * line breaks (\n). Requires a bounded width (fillWidth or an explicit width).
     */
    public bool $wrap = false;

    /** Line advance as a multiple of the font size, used when wrapping. */
    public float $lineHeight = 1.3;

    /**
     * Optional font name for this label only (e.g. a lighter body face for long
     * prose while the surrounding UI uses a heavier default). When set, draw()
     * selects it, renders, then restores the tree's default font so sibling
     * widgets are unaffected. Null keeps the current tree font.
     */
    public ?string $font = null;

    /**
     * Horizontal text alignment within the label's bounds: 'left' (default),
     * 'right', or 'center'. Give a value column a fixed width and 'right' so
     * every row's value lines up flush at the same edge. Applies to the
     * single-line path (wrapped text stays left-aligned).
     */
    public string $align = 'left';

    /**
     * Vertical alignment of a single-line label within its bounds: 'top'
     * (default), 'center' or 'bottom'. Use 'center' to middle text inside a
     * padded pill/badge or a fixed-height row. Ignored when wrapping.
     */
    public string $valign = 'top';

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

        $textW = self::textWidth($this->text, $fs);
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

        // Badge/pill background behind the text. Skipped for empty text so a
        // bound badge shows nothing at zero without the view-model juggling the
        // colour too.
        if ($this->backgroundColor !== null && $this->text !== '') {
            $b = $this->bounds;
            $renderer->drawRoundedRect($b->x, $b->y, $b->width, $b->height, $this->backgroundRadius, $this->backgroundColor);
        }

        // Explicit left/top anchor: the renderer's text align is sticky global
        // state, so without this a Label after a centered widget would inherit
        // CENTER|MIDDLE and render offset from its top-left origin.
        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);

        // Per-label font override — set it, and restore the tree default after
        // drawing so the sticky global font doesn't leak to sibling widgets.
        if ($this->font !== null) {
            $renderer->setFont($this->font);
        }

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
        } else {
            [$hAlign, $tx] = match ($this->align) {
                'right'  => [TextAlign::RIGHT, $this->bounds->x + $this->bounds->width - $this->padding->right],
                'center' => [TextAlign::CENTER, $this->bounds->x + $this->bounds->width / 2.0],
                default  => [TextAlign::LEFT, $x],
            };
            [$vAlign, $ty] = match ($this->valign) {
                'center' => [TextAlign::MIDDLE, $this->bounds->y + $this->bounds->height / 2.0],
                'bottom' => [TextAlign::BOTTOM, $this->bounds->y + $this->bounds->height - $this->padding->bottom],
                default  => [TextAlign::TOP, $y],
            };
            $renderer->setTextAlign($hAlign | $vAlign);
            $renderer->drawText($this->text, $tx, $ty, $fs, $color);
        }

        if ($this->font !== null) {
            $renderer->setFont($style->fontName);
        }
    }

    /** Advance of a regular glyph, as a multiple of the font size. */
    private const NARROW_ADVANCE = 0.62;

    /** Advance of a full-width (CJK, Hangul, full-width form) glyph. */
    private const WIDE_ADVANCE = 1.0;

    /**
     * Estimated drawn width: fontSize * 0.62 per regular glyph (average advance
     * of the UI font; 0.55 under-measured and clipped auto-sized text) and a
     * whole fontSize per full-width glyph.
     */
    private static function textWidth(string $text, float $fontSize): float
    {
        $length = mb_strlen($text);
        $wide = preg_match_all('/[' . LineBreaks::WIDE . ']/u', $text);

        return (($length - $wide) * self::NARROW_ADVANCE + $wide * self::WIDE_ADVANCE) * $fontSize;
    }

    /**
     * Greedy wrap to $maxWidth using the same width estimate measure() uses, so
     * both agree on the line count. Lines break at spaces and between full-width
     * characters (scripts without spaces), never before closing punctuation; a
     * word wider than the whole line is split. Hard breaks (\n / \r\n) always
     * start a new line.
     *
     * @return list<string>
     */
    private static function wrapLines(string $text, float $maxWidth, float $fontSize): array
    {
        $limit = $maxWidth > 0.0 ? $maxWidth + 1e-6 : INF;
        $space = self::textWidth(' ', $fontSize);
        $tokenPattern = '/ +|[' . LineBreaks::WIDE . '][' . LineBreaks::NO_LINE_START . ']*|[^ ' . LineBreaks::WIDE . ']+/u';

        $lines = [];
        $paragraphs = preg_split('/\r\n?|\n/', $text) ?: [$text];
        foreach ($paragraphs as $paragraph) {
            $line = '';
            $lineWidth = 0.0;
            $spaceBefore = false;
            preg_match_all($tokenPattern, $paragraph, $matches);
            foreach ($matches[0] as $token) {
                if ($token[0] === ' ') {
                    $spaceBefore = true;
                    continue;
                }
                $gap = $spaceBefore && $line !== '' ? $space : 0.0;
                $spaceBefore = false;
                $width = self::textWidth($token, $fontSize);

                if ($line !== '' && $lineWidth + $gap + $width <= $limit) {
                    $line .= ($gap > 0.0 ? ' ' : '') . $token;
                    $lineWidth += $gap + $width;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                }
                $line = '';
                $lineWidth = 0.0;
                // A single token wider than the line is cut glyph by glyph.
                foreach (mb_str_split($token) as $glyph) {
                    $glyphWidth = self::textWidth($glyph, $fontSize);
                    if ($line !== '' && $lineWidth + $glyphWidth > $limit) {
                        $lines[] = $line;
                        $line = '';
                        $lineWidth = 0.0;
                    }
                    $line .= $glyph;
                    $lineWidth += $glyphWidth;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
