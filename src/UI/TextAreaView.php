<?php

declare(strict_types=1);

namespace PHPolygon\UI;

/**
 * Geometry of a {@see UIContext::textArea()} as drawn in the current frame.
 *
 * Lets a caller line up its own drawings with the text: a line-number gutter
 * beside the field, highlight bands under single lines (via the textArea
 * underlay callback), markers next to a row. Rows are 0-based line indices of
 * the whole text; only rows in [firstRow, firstRow + visibleRows) are on
 * screen.
 *
 * `x`/`y`/`width`/`height` is the field rectangle including its padding.
 * `textX`/`textY` is where row `firstRow`, column 0 would start with no
 * horizontal scroll; the text actually starts at `textX - scrollX`.
 */
final readonly class TextAreaView
{
    /** Rows scrolled per mouse-wheel notch. */
    public const int WHEEL_ROWS = 3;

    public function __construct(
        public string $id,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $textX,
        public float $textY,
        public float $innerWidth,
        public float $lineHeight,
        public int $firstRow,
        public int $visibleRows,
        public int $lineCount,
        public float $scrollX,
        public float $contentWidth,
        public bool $focused,
    ) {
    }

    /** Top edge (screen y) of $row – outside the field when the row is scrolled away. */
    public function rowY(int $row): float
    {
        return $this->textY + ($row - $this->firstRow) * $this->lineHeight;
    }

    public function isRowVisible(int $row): bool
    {
        return $row >= $this->firstRow && $row < $this->firstRow + $this->visibleRows && $row < max(1, $this->lineCount);
    }

    /** Last row that is on screen (inclusive). */
    public function lastVisibleRow(): int
    {
        return min($this->lineCount, $this->firstRow + $this->visibleRows) - 1;
    }
}
