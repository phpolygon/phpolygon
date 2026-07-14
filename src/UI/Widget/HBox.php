<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\UI\UIStyle;

/**
 * Horizontal box layout — stacks children left to right.
 */
class HBox extends Widget
{
    public float $spacing = 4.0;

    /**
     * Vertical alignment of children within the row: 'top' (default), 'center'
     * or 'bottom'. Use 'center' to line up items of different heights — e.g. a
     * short text label next to a taller progress bar.
     */
    public string $crossAlign = 'top';

    public function __construct(float $spacing = 4.0)
    {
        parent::__construct();
        $this->spacing = $spacing;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $contentW = $availableWidth - $this->padding->horizontal();
        $contentH = $availableHeight - $this->padding->vertical();

        $totalWidth = 0.0;
        $maxHeight = 0.0;
        $fillCount = 0;

        foreach ($this->children as $i => $child) {
            if (!$child->visible) continue;

            if ($child->sizing->fillWidth) {
                $fillCount++;
            } else {
                $child->measure($contentW, $contentH, $style);
                $totalWidth += $child->getMeasuredWidth() + $child->margin->horizontal();
            }
            if ($i > 0) $totalWidth += $this->spacing;
        }

        if ($fillCount > 0) {
            $remaining = max(0.0, $contentW - $totalWidth);
            $perChild = $remaining / $fillCount;
            foreach ($this->children as $child) {
                if (!$child->visible || !$child->sizing->fillWidth) continue;
                $child->measure($perChild - $child->margin->horizontal(), $contentH, $style);
                $totalWidth += $child->getMeasuredWidth() + $child->margin->horizontal();
            }
        }

        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $childH = $child->getMeasuredHeight() + $child->margin->vertical();
            if ($childH > $maxHeight) $maxHeight = $childH;
        }

        $this->measuredWidth = $this->resolveSize($this->sizing->fillWidth, $this->sizing->width,
            $totalWidth + $this->padding->horizontal(), $availableWidth,
            $this->sizing->minWidth, $this->sizing->maxWidth);
        $this->measuredHeight = $this->resolveSize($this->sizing->fillHeight, $this->sizing->height,
            $maxHeight + $this->padding->vertical(), $availableHeight,
            $this->sizing->minHeight, $this->sizing->maxHeight);
    }

    public function layout(UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $content = $this->contentRect();

        // Re-distribute the fillWidth share against the ACTUAL content width.
        // measure() may have run with a different available width than the final
        // bounds (e.g. this HBox sits in a fixed-width ScrollView whose parent
        // measured it against the full window), so using each child's measured
        // width here would overflow. Mirrors VBox's fillHeight re-fit.
        $fixedW = 0.0;
        $fillCount = 0;
        $visible = 0;
        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $visible++;
            if ($child->sizing->fillWidth) {
                $fillCount++;
            } else {
                $fixedW += $child->getMeasuredWidth() + $child->margin->horizontal();
            }
        }
        if ($visible > 1) {
            $fixedW += $this->spacing * ($visible - 1);
        }
        $perFill = $fillCount > 0 ? max(0.0, ($content->width - $fixedW) / $fillCount) : 0.0;

        $x = $content->x;
        foreach ($this->children as $child) {
            if (!$child->visible) continue;

            $childW = $child->sizing->fillWidth
                ? max(0.0, $perFill - $child->margin->horizontal())
                : $child->getMeasuredWidth();
            $childH = $child->sizing->fillHeight
                ? $content->height - $child->margin->vertical()
                : $child->getMeasuredHeight();

            $childX = $x + $child->margin->left;
            $childY = match ($this->crossAlign) {
                'center' => $content->y + max(0.0, ($content->height - $childH) / 2.0),
                'bottom' => $content->y + $content->height - $childH - $child->margin->bottom,
                default  => $content->y + $child->margin->top,
            };

            $child->setBounds(new Rect($childX, $childY, $childW, $childH));
            $child->layout($style);

            $x = $childX + $childW + $child->margin->right + $this->spacing;
        }
    }

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            if (self::isClipped($child)) continue;
            $child->draw($renderer, $style);
        }
    }

    private function resolveSize(bool $fill, float $fixed, float $content, float $available, float $min, float $max): float
    {
        if ($fill) return $available;
        if ($fixed > 0) return $fixed;
        return max($min, min($max, $content));
    }
}
