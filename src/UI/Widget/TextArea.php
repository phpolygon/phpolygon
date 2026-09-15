<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

/**
 * A multi-line text field.
 *
 * It extends {@see TextInput} on purpose: focus, the two-way `text` binding and
 * a host that holds back hotkeys while a text field has focus all key on
 * TextInput, and all of them apply here unchanged. What this adds is lines —
 * Enter, word wrapping, moving between lines, a visible row count, scrolling
 * that follows the caret, and an optional length limit.
 *
 * Wrapping measures with the renderer, so lines break where the text really
 * ends rather than at an estimated character width. The result is cached per
 * text and width; typing does not re-measure a long text every frame.
 */
class TextArea extends TextInput
{
    /** Visible rows when the height is not fixed by the layout. */
    public int $rows = 4;

    /** Maximum number of characters, newlines included; 0 = no limit. */
    public int $maxLength = 0;

    /** Line advance as a multiple of the font size. */
    public float $lineHeight = 1.3;

    /** Index of the first line shown. */
    public int $firstVisibleLine = 0;

    /**
     * Wrapped lines from the last draw, as [start, end) character offsets.
     *
     * @var list<array{int, int}>
     */
    private array $lines = [[0, 0]];
    private string $wrappedText = '';
    private float $wrappedWidth = -1.0;
    private float $wrappedFontSize = -1.0;
    private bool $followCaret = true;
    private int $visibleRows = 1;
    /** @var null|\Closure(string): float */
    private ?\Closure $measure = null;

    public function __construct(string $label = '', string $text = '', string $placeholder = '')
    {
        parent::__construct($label, $text, $placeholder);
    }

    // ── Geometry ──────────────────────────────────────────────────────────────

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $fieldH = max(1, $this->rows) * $style->fontSize * $this->lineHeight + $this->padding->vertical();

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : 300.0);
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $labelH + $fieldH);
    }

    /**
     * Greedy word wrap into [start, end) character offsets of $text.
     *
     * Hard breaks end a line (the newline itself belongs to no line); a soft
     * break keeps the space at the end of the line it ends. Between full-width
     * characters a line may break without a space ({@see LineBreaks}). A word
     * wider than the line is broken between characters. Offsets are
     * characters, not bytes, so a multi-byte letter is never split.
     *
     * @param \Closure(string): float $measure width of a piece of text
     * @return list<array{int, int}>
     */
    public static function wrap(string $text, float $maxWidth, \Closure $measure): array
    {
        $chars = mb_str_split($text);
        $count = count($chars);
        $lines = [];
        $start = 0;
        $breakAt = -1;

        $i = 0;
        while ($i < $count) {
            $char = $chars[$i];
            if ($char === "\n") {
                $lines[] = [$start, $i];
                $start = $i + 1;
                $breakAt = -1;
                $i++;
                continue;
            }

            if ($i > $start && LineBreaks::canBreakBefore($chars, $i)) {
                $breakAt = $i;
            }

            if ($i > $start && $measure(implode('', array_slice($chars, $start, $i - $start + 1))) > $maxWidth) {
                if ($breakAt > $start) {
                    $lines[] = [$start, $breakAt];
                    $start = $breakAt;
                } else {
                    $lines[] = [$start, $i];
                    $start = $i;
                }
                $breakAt = -1;
                // Re-examine $i as the first character(s) of the new line.
                for ($j = $start + 1; $j < $i; $j++) {
                    if (LineBreaks::canBreakBefore($chars, $j)) {
                        $breakAt = $j;
                    }
                }
                continue;
            }

            $i++;
        }

        $lines[] = [$start, $count];

        return $lines;
    }

    /**
     * The line the caret stands on: the last one starting at or before it.
     *
     * @param list<array{int, int}> $lines
     */
    public static function lineOf(array $lines, int $cursor): int
    {
        $index = 0;
        foreach ($lines as $i => [$start]) {
            if ($start > $cursor) {
                break;
            }
            $index = $i;
        }

        return $index;
    }

    // ── Editing ───────────────────────────────────────────────────────────────

    /** @param list<string> $chars */
    public function insertChars(array $chars): void
    {
        foreach ($chars as $char) {
            if ($this->maxLength > 0 && mb_strlen($this->text) >= $this->maxLength) {
                break;
            }
            parent::insertChars([$char]);
        }
        if ($chars !== []) {
            $this->followCaret = true;
        }
    }

    public function newline(): void
    {
        $this->insertChars(["\n"]);
    }

    public function backspace(): void
    {
        parent::backspace();
        $this->followCaret = true;
    }

    public function delete(): void
    {
        parent::delete();
        $this->followCaret = true;
    }

    public function moveCursorLeft(): void
    {
        parent::moveCursorLeft();
        $this->followCaret = true;
    }

    public function moveCursorRight(): void
    {
        parent::moveCursorRight();
        $this->followCaret = true;
    }

    /**
     * Move the caret $direction lines (−1 up, +1 down), keeping its horizontal
     * position as closely as the target line allows. Past the first line the
     * caret goes to the start, past the last it stays.
     *
     * @param null|list<array{int, int}>   $lines   defaults to the last drawn wrap
     * @param null|\Closure(string): float $measure defaults to the last renderer
     */
    public function moveCursorVertically(int $direction, ?array $lines = null, ?\Closure $measure = null): void
    {
        $lines ??= $this->lines;
        $measure ??= $this->measure ?? static fn (string $t): float => (float) mb_strlen($t);

        $line = self::lineOf($lines, $this->cursorPos);
        $target = $line + $direction;
        if ($target < 0) {
            $this->cursorPos = 0;
            $this->followCaret = true;
            return;
        }
        if ($target >= count($lines)) {
            return;
        }

        [$start] = $lines[$line];
        $x = $measure(mb_substr($this->text, $start, $this->cursorPos - $start));

        [$tStart, $tEnd] = $lines[$target];
        $best = $tStart;
        $bestDistance = PHP_FLOAT_MAX;
        for ($pos = $tStart; $pos <= $tEnd; $pos++) {
            $distance = abs($measure(mb_substr($this->text, $tStart, $pos - $tStart)) - $x);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $pos;
            }
        }

        // A soft-wrapped line ends where the next begins; standing on that
        // offset would put the caret on the next line instead.
        if ($best === $tEnd && $target + 1 < count($lines) && $lines[$target + 1][0] === $tEnd && $tEnd > $tStart) {
            $best = $tEnd - 1;
        }

        $this->cursorPos = $best;
        $this->followCaret = true;
    }

    /**
     * Home / End within the caret's line.
     *
     * @param null|list<array{int, int}> $lines defaults to the last drawn wrap
     */
    public function moveCursorToLineEdge(bool $end, ?array $lines = null): void
    {
        $lines ??= $this->lines;
        [$start, $stop] = $lines[self::lineOf($lines, $this->cursorPos)];
        $this->cursorPos = $end ? $stop : $start;
        $this->followCaret = true;
    }

    /** Bring the caret into view on the next draw. */
    public function followCaret(): void
    {
        $this->followCaret = true;
    }

    /** Scroll by whole lines without moving the caret. */
    public function scrollLines(int $delta): void
    {
        $max = max(0, count($this->lines) - $this->visibleRows);
        $this->firstVisibleLine = max(0, min($max, $this->firstVisibleLine + $delta));
        $this->followCaret = false;
    }

    // ── Drawing ───────────────────────────────────────────────────────────────

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;
        $fontSize = $style->fontSize;
        $lineH = $fontSize * $this->lineHeight;
        $labelH = $this->label !== '' ? $fontSize + 4.0 : 0.0;

        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);

        if ($this->label !== '') {
            $renderer->drawText($this->label, $b->x, $b->y, $fontSize, $style->textColor);
        }

        $fieldY = $b->y + $labelH;
        $fieldH = $b->height - $labelH;
        $borderColor = $this->focused ? $style->accentColor : $style->borderColor;
        $borderW = $this->focused ? 2.0 : $style->borderWidth;
        $renderer->drawRoundedRect($b->x, $fieldY, $b->width, $fieldH, $style->borderRadius, $style->backgroundColor);
        $renderer->drawRectOutline($b->x, $fieldY, $b->width, $fieldH, $borderColor, $borderW);

        $measure = static fn (string $t): float => $t === '' ? 0.0 : $renderer->measureText($t, $fontSize)->width;
        $this->measure = $measure;
        $scrollbarW = 4.0;
        $textW = max(1.0, $b->width - $this->padding->horizontal() - $scrollbarW - 2.0);

        if ($this->text !== $this->wrappedText || $textW !== $this->wrappedWidth || $fontSize !== $this->wrappedFontSize) {
            $this->lines = self::wrap($this->text, $textW, $measure);
            $this->wrappedText = $this->text;
            $this->wrappedWidth = $textW;
            $this->wrappedFontSize = $fontSize;
        }
        $this->cursorPos = max(0, min($this->cursorPos, mb_strlen($this->text)));

        $this->visibleRows = max(1, (int) floor(($fieldH - $this->padding->vertical()) / $lineH));
        $lineCount = count($this->lines);
        $caretLine = self::lineOf($this->lines, $this->cursorPos);

        if ($this->followCaret) {
            if ($caretLine < $this->firstVisibleLine) {
                $this->firstVisibleLine = $caretLine;
            } elseif ($caretLine >= $this->firstVisibleLine + $this->visibleRows) {
                $this->firstVisibleLine = $caretLine - $this->visibleRows + 1;
            }
            $this->followCaret = false;
        }
        $this->firstVisibleLine = max(0, min($this->firstVisibleLine, max(0, $lineCount - $this->visibleRows)));

        $textX = $b->x + $this->padding->left;
        $textY = $fieldY + $this->padding->top;

        if ($this->text === '') {
            if ($this->placeholder !== '') {
                $renderer->drawText($this->placeholder, $textX, $textY, $fontSize, $style->textColor->withAlpha(0.4));
            }
        } else {
            $last = min($lineCount, $this->firstVisibleLine + $this->visibleRows);
            for ($i = $this->firstVisibleLine; $i < $last; $i++) {
                [$start, $end] = $this->lines[$i];
                $piece = rtrim(mb_substr($this->text, $start, $end - $start), "\n");
                if ($piece !== '') {
                    $renderer->drawText($piece, $textX, $textY + ($i - $this->firstVisibleLine) * $lineH, $fontSize, $style->textColor);
                }
            }
        }

        if ($this->focused && $caretLine >= $this->firstVisibleLine && $caretLine < $this->firstVisibleLine + $this->visibleRows) {
            [$start] = $this->lines[$caretLine];
            $caretX = $textX + $measure(mb_substr($this->text, $start, $this->cursorPos - $start));
            $caretY = $textY + ($caretLine - $this->firstVisibleLine) * $lineH;
            $renderer->drawRect($caretX, $caretY, 1.5, $fontSize, $style->accentColor);
        }

        // A thin bar shows there is more text above or below.
        if ($lineCount > $this->visibleRows) {
            $trackX = $b->x + $b->width - $scrollbarW - 3.0;
            $trackY = $fieldY + 3.0;
            $trackH = $fieldH - 6.0;
            $thumbH = max(8.0, $trackH * $this->visibleRows / $lineCount);
            $thumbY = $trackY + ($trackH - $thumbH) * ($this->firstVisibleLine / max(1, $lineCount - $this->visibleRows));
            $renderer->drawRoundedRect($trackX, $thumbY, $scrollbarW, $thumbH, $scrollbarW / 2, $style->borderColor);
        }
    }
}
