<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\UI\UIStyle;

/**
 * Vertical box layout — stacks children top to bottom.
 */
class VBox extends Widget
{
    public float $spacing = 4.0;

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

        $totalHeight = 0.0;
        $maxWidth = 0.0;
        $fillCount = 0;

        foreach ($this->children as $i => $child) {
            if (!$child->visible) continue;

            if ($child->sizing->fillHeight) {
                $fillCount++;
            } else {
                $child->measure($contentW, $contentH, $style);
                $totalHeight += $child->getMeasuredHeight() + $child->margin->vertical();
            }
            if ($i > 0) $totalHeight += $this->spacing;
        }

        // Fill children get remaining space
        if ($fillCount > 0) {
            $remaining = max(0.0, $contentH - $totalHeight);
            $perChild = $remaining / $fillCount;
            foreach ($this->children as $child) {
                if (!$child->visible || !$child->sizing->fillHeight) continue;
                $child->measure($contentW, $perChild - $child->margin->vertical(), $style);
                $totalHeight += $child->getMeasuredHeight() + $child->margin->vertical();
            }
        }

        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $childW = $child->getMeasuredWidth() + $child->margin->horizontal();
            if ($childW > $maxWidth) $maxWidth = $childW;
        }

        $this->measuredWidth = $this->resolveWidth($maxWidth + $this->padding->horizontal(), $availableWidth);
        $this->measuredHeight = $this->resolveHeight($totalHeight + $this->padding->vertical(), $availableHeight);
    }

    public function layout(UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $content = $this->contentRect();
        $y = $content->y;

        foreach ($this->children as $child) {
            if (!$child->visible) continue;

            $childW = $child->sizing->fillWidth
                ? $content->width - $child->margin->horizontal()
                : $child->getMeasuredWidth();

            $childX = $content->x + $child->margin->left;
            $childY = $y + $child->margin->top;

            $child->setBounds(new Rect($childX, $childY, $childW, $child->getMeasuredHeight()));
            $child->layout($style);

            $y = $childY + $child->getMeasuredHeight() + $child->margin->bottom + $this->spacing;
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

    private function resolveWidth(float $content, float $available): float
    {
        if ($this->sizing->fillWidth) return $available;
        if ($this->sizing->width > 0) return $this->sizing->width;
        return clamp($content, $this->sizing->minWidth, $this->sizing->maxWidth);
    }

    private function resolveHeight(float $content, float $available): float
    {
        if ($this->sizing->fillHeight) return $available;
        if ($this->sizing->height > 0) return $this->sizing->height;
        return clamp($content, $this->sizing->minHeight, $this->sizing->maxHeight);
    }
}

function clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}
