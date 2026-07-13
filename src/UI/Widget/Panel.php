<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

/**
 * Panel with a title bar — wraps a content widget tree.
 */
class Panel extends Widget
{
    public string $title;

    public function __construct(string $title = '')
    {
        parent::__construct();
        $this->title = $title;
        $this->padding = EdgeInsets::all(8.0);
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $titleH = $this->title !== '' ? $style->fontSize + $this->padding->vertical() : 0.0;
        $contentW = $availableWidth - $this->padding->horizontal();
        $contentH = $availableHeight - $this->padding->vertical() - $titleH;

        $maxW = 0.0;
        $totalH = 0.0;

        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $child->measure($contentW, $contentH, $style);
            $w = $child->getMeasuredWidth() + $child->margin->horizontal();
            if ($w > $maxW) $maxW = $w;
            $totalH += $child->getMeasuredHeight() + $child->margin->vertical();
        }

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : $maxW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $totalH + $titleH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $titleH = $this->title !== '' ? $style->fontSize + $this->padding->vertical() : 0.0;

        $content = new Rect(
            $this->bounds->x + $this->padding->left,
            $this->bounds->y + $titleH + $this->padding->top,
            max(0.0, $this->bounds->width - $this->padding->horizontal()),
            max(0.0, $this->bounds->height - $titleH - $this->padding->vertical()),
        );

        $y = $content->y;
        foreach ($this->children as $child) {
            if (!$child->visible) continue;

            $childW = $child->sizing->fillWidth ? $content->width - $child->margin->horizontal() : $child->getMeasuredWidth();
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
        $titleH = $this->title !== '' ? $style->fontSize + $this->padding->vertical() : 0.0;

        // Background
        $renderer->drawRoundedRect($b->x, $b->y, $b->width, $b->height, $style->borderRadius, $style->backgroundColor);

        // Title bar
        if ($this->title !== '') {
            $renderer->drawRoundedRect($b->x, $b->y, $b->width, $titleH, $style->borderRadius, $style->activeColor);
            $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
            $renderer->drawText($this->title, $b->x + $this->padding->left, $b->y + $this->padding->top, $style->fontSize, $style->textColor);
        }

        // Border
        $renderer->drawRectOutline($b->x, $b->y, $b->width, $b->height, $style->borderColor, $style->borderWidth);

        // Children
        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $child->draw($renderer, $style);
        }
    }
}
