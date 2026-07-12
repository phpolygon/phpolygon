<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\UI\UIStyle;

/**
 * Scrollable container — clips children and offsets by scroll position.
 */
class ScrollView extends Widget
{
    private float $scrollY = 0.0;
    private float $contentHeight = 0.0;
    private float $scrollBarWidth = 6.0;
    private bool $scrollBarVisible = false;

    public function getScrollY(): float
    {
        return $this->scrollY;
    }

    public function setScrollY(float $y): void
    {
        $this->scrollY = max(0.0, min($y, $this->getMaxScroll()));
    }

    public function scrollBy(float $deltaY): void
    {
        $this->setScrollY($this->scrollY + $deltaY);
    }

    public function getMaxScroll(): float
    {
        return max(0.0, $this->contentHeight - $this->bounds->height + $this->padding->vertical());
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $contentW = $availableWidth - $this->padding->horizontal();

        $totalH = 0.0;
        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $child->measure($contentW, PHP_FLOAT_MAX, $style);
            $totalH += $child->getMeasuredHeight() + $child->margin->vertical();
        }
        $this->contentHeight = $totalH;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : $contentW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : min($totalH + $this->padding->vertical(), $availableHeight));

        $this->scrollBarVisible = $this->contentHeight > ($this->measuredHeight - $this->padding->vertical());
    }

    public function layout(UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $content = $this->contentRect();
        $y = $content->y - $this->scrollY;

        foreach ($this->children as $child) {
            if (!$child->visible) continue;

            $childW = $child->sizing->fillWidth
                ? $content->width - $child->margin->horizontal()
                : $child->getMeasuredWidth();

            $childX = $content->x + $child->margin->left;
            $childY = $y + $child->margin->top;

            $child->setBounds(new Rect($childX, $childY, $childW, $child->getMeasuredHeight()));
            $child->layout($style);

            $y = $childY + $child->getMeasuredHeight() + $child->margin->bottom;
        }
    }

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $b = $this->bounds;

        $renderer->pushScissor($b->x, $b->y, $b->width, $b->height);

        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $cb = $child->getBounds();
            // Skip children entirely outside visible area
            if ($cb->bottom() < $b->y || $cb->top() > $b->bottom()) continue;
            $child->draw($renderer, $style);
        }

        // Draw scrollbar
        if ($this->scrollBarVisible) {
            $this->drawScrollBar($renderer, $style);
        }

        $renderer->popScissor();
    }

    private function drawScrollBar(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $b = $this->bounds;
        $viewH = $b->height - $this->padding->vertical();
        $ratio = $viewH / $this->contentHeight;
        $barH = max(20.0, $viewH * $ratio);
        $maxScroll = $this->getMaxScroll();
        $barY = $maxScroll > 0
            ? $b->y + $this->padding->top + ($viewH - $barH) * ($this->scrollY / $maxScroll)
            : $b->y + $this->padding->top;
        $barX = $b->right() - $this->scrollBarWidth - 2.0;

        $renderer->drawRoundedRect($barX, $barY, $this->scrollBarWidth, $barH, $this->scrollBarWidth * 0.5,
            $style->borderColor->withAlpha(0.4));
    }

    /**
     * Handle scroll input for this view. Call from WidgetTree's update.
     */
    public function handleScroll(InputInterface $input): void
    {
        $scrollDelta = $input->getScrollY();
        if (abs($scrollDelta) > 0.01) {
            $this->scrollBy(-$scrollDelta * 30.0);
        }
    }
}
