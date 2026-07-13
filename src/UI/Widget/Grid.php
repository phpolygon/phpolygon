<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\UI\UIStyle;

/**
 * N-column grid layout with SHARED column widths across rows.
 *
 * Unlike stacking a series of {@see HBox}es, a Grid measures every cell and
 * makes each column as wide as its widest cell across all rows, so columns line
 * up vertically — the property panels need for tables, ledgers and expertise
 * grids. Children flow row-major into cells (left to right, top to bottom).
 *
 * When {@see $sizing}->fillWidth (or a fixed width) leaves space beyond the
 * natural column widths, the leftover is distributed evenly across all columns.
 *
 * Works as a {@see Repeater} template child: bind the Repeater's collection and
 * put a Grid in the row template, or make the Grid itself the repeated node.
 *
 *   { "_widget": "PHPolygon\\UI\\Widget\\Grid", "columns": 3,
 *     "columnSpacing": 8, "rowSpacing": 4, "children": [ ... ] }
 */
class Grid extends Widget
{
    /** Number of columns; children flow row-major into cells. */
    public int $columns = 1;

    /** Horizontal gap between columns, in pixels. */
    public float $columnSpacing = 4.0;

    /** Vertical gap between rows, in pixels. */
    public float $rowSpacing = 4.0;

    /** @var list<float> Per-column widths computed in measure(), consumed in layout(). */
    private array $columnWidths = [];

    /** @var list<float> Per-row heights computed in measure(), consumed in layout(). */
    private array $rowHeights = [];

    public function __construct(int $columns = 1, float $columnSpacing = 4.0, float $rowSpacing = 4.0)
    {
        parent::__construct();
        $this->columns = max(1, $columns);
        $this->columnSpacing = $columnSpacing;
        $this->rowSpacing = $rowSpacing;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $columns = max(1, $this->columns);
        $contentW = $availableWidth - $this->padding->horizontal();
        $contentH = $availableHeight - $this->padding->vertical();

        $cells = $this->visibleChildren();
        $rows = $cells === [] ? 0 : (int) ceil(count($cells) / $columns);

        // Available width per cell for the initial (natural) measure pass.
        $cellAvailW = max(0.0, ($contentW - ($columns - 1) * $this->columnSpacing) / $columns);

        $colWidths = array_fill(0, $columns, 0.0);
        $rowHeights = array_fill(0, max(0, $rows), 0.0);

        foreach ($cells as $index => $child) {
            $col = $index % $columns;
            $row = intdiv($index, $columns);

            $child->measure($cellAvailW, $contentH, $style);
            $w = $child->getMeasuredWidth() + $child->margin->horizontal();
            $h = $child->getMeasuredHeight() + $child->margin->vertical();

            if ($w > $colWidths[$col]) {
                $colWidths[$col] = $w;
            }
            if ($h > $rowHeights[$row]) {
                $rowHeights[$row] = $h;
            }
        }

        $naturalW = array_sum($colWidths) + ($columns - 1) * $this->columnSpacing;

        // Distribute leftover space evenly when we fill or have a fixed width.
        $wantsWidth = $this->sizing->fillWidth || $this->sizing->width > 0.0;
        if ($wantsWidth) {
            $targetContentW = $this->sizing->fillWidth
                ? $contentW
                : max(0.0, $this->sizing->width - $this->padding->horizontal());
            $leftover = $targetContentW - $naturalW;
            if ($leftover > 0.0) {
                $per = $leftover / $columns;
                foreach ($colWidths as $i => $cw) {
                    $colWidths[$i] = $cw + $per;
                }
                $naturalW = $targetContentW;
            }
        }

        $this->columnWidths = $colWidths;
        $this->rowHeights = $rowHeights;

        $naturalH = array_sum($rowHeights) + max(0, $rows - 1) * $this->rowSpacing;

        $this->measuredWidth = $this->resolveSize(
            $this->sizing->fillWidth, $this->sizing->width,
            $naturalW + $this->padding->horizontal(), $availableWidth,
            $this->sizing->minWidth, $this->sizing->maxWidth,
        );
        $this->measuredHeight = $this->resolveSize(
            $this->sizing->fillHeight, $this->sizing->height,
            $naturalH + $this->padding->vertical(), $availableHeight,
            $this->sizing->minHeight, $this->sizing->maxHeight,
        );
    }

    public function layout(UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $columns = max(1, $this->columns);
        $content = $this->contentRect();

        $cells = $this->visibleChildren();

        // Column x-offsets (start of each column, before per-cell margin).
        $colX = [];
        $x = $content->x;
        foreach ($this->columnWidths as $i => $w) {
            $colX[$i] = $x;
            $x += $w + $this->columnSpacing;
        }

        // Row y-offsets.
        $rowY = [];
        $y = $content->y;
        foreach ($this->rowHeights as $i => $h) {
            $rowY[$i] = $y;
            $y += $h + $this->rowSpacing;
        }

        foreach ($cells as $index => $child) {
            $col = $index % $columns;
            $row = intdiv($index, $columns);

            $cellW = $this->columnWidths[$col] ?? 0.0;
            $cellH = $this->rowHeights[$row] ?? 0.0;

            $childW = $child->sizing->fillWidth
                ? max(0.0, $cellW - $child->margin->horizontal())
                : $child->getMeasuredWidth();
            $childH = $child->sizing->fillHeight
                ? max(0.0, $cellH - $child->margin->vertical())
                : $child->getMeasuredHeight();

            $childX = ($colX[$col] ?? $content->x) + $child->margin->left;
            $childY = ($rowY[$row] ?? $content->y) + $child->margin->top;

            $child->setBounds(new Rect($childX, $childY, $childW, $childH));
            $child->layout($style);
        }
    }

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $child->draw($renderer, $style);
        }
    }

    /** @return list<Widget> */
    private function visibleChildren(): array
    {
        return array_values(array_filter($this->children, fn (Widget $c) => $c->visible));
    }

    private function resolveSize(bool $fill, float $fixed, float $content, float $available, float $min, float $max): float
    {
        if ($fill) return $available;
        if ($fixed > 0) return $fixed;
        return max($min, min($max, $content));
    }
}
