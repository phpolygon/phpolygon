<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\UI\UIStyle;

/**
 * Stack layout — overlays children on top of each other.
 *
 * Each child can be anchored within the stack's bounds.
 */
class Stack extends Widget
{
    /**
     * Optional card-hover fill: when set, the whole stack tints with this colour
     * while the pointer is over it, drawn BEHIND the children. This lets a rich
     * card (content widgets + a flat whole-card click overlay) highlight on hover
     * the way a single ghost Button does, without the fill covering its content.
     */
    public ?Color $hoverColor = null;

    /** True while the pointer is over the stack; set each frame by {@see WidgetTree}. */
    public bool $hovered = false;

    /** @var array<int, Anchor> child index → anchor */
    private array $childAnchors = [];

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $contentW = $availableWidth - $this->padding->horizontal();
        $contentH = $availableHeight - $this->padding->vertical();

        $maxW = 0.0;
        $maxH = 0.0;

        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $child->measure($contentW, $contentH, $style);
            // A fill child conforms TO the stack in that axis (it fills the
            // content rect in layout()), so it must not drive the stack's own
            // size — otherwise an overlay like a full-card click target measured
            // at the full available height would balloon the stack. Only
            // intrinsically-sized children establish the stack's extent.
            if (!$child->sizing->fillWidth) {
                $w = $child->getMeasuredWidth() + $child->margin->horizontal();
                if ($w > $maxW) $maxW = $w;
            }
            if (!$child->sizing->fillHeight) {
                $h = $child->getMeasuredHeight() + $child->margin->vertical();
                if ($h > $maxH) $maxH = $h;
            }
        }

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : $maxW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $maxH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $content = $this->contentRect();

        foreach ($this->children as $i => $child) {
            if (!$child->visible) continue;

            $anchor = $this->childAnchors[$i] ?? Anchor::TopLeft;
            $cw = $child->sizing->fillWidth ? $content->width - $child->margin->horizontal() : $child->getMeasuredWidth();
            $ch = $child->sizing->fillHeight ? $content->height - $child->margin->vertical() : $child->getMeasuredHeight();

            [$x, $y] = $this->anchorPosition($anchor, $content, $cw, $ch, $child->margin);

            $child->setBounds(new Rect($x, $y, $cw, $ch));
            $child->layout($style);
        }
    }

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        if ($this->hovered && $this->hoverColor !== null) {
            $b = $this->bounds;
            $renderer->drawRoundedRect($b->x, $b->y, $b->width, $b->height, $style->borderRadius, $this->hoverColor);
        }
        foreach ($this->children as $child) {
            if (!$child->visible) continue;
            $child->draw($renderer, $style);
        }
    }

    /**
     * Add a child with a specific anchor.
     */
    public function addAnchored(Widget $child, Anchor $anchor): static
    {
        $index = count($this->children);
        $this->addChild($child);
        $this->childAnchors[$index] = $anchor;
        return $this;
    }

    /**
     * @return array{float, float}
     */
    private function anchorPosition(Anchor $anchor, Rect $content, float $cw, float $ch, EdgeInsets $margin): array
    {
        $x = match ($anchor) {
            Anchor::TopLeft, Anchor::CenterLeft, Anchor::BottomLeft => $content->x + $margin->left,
            Anchor::TopCenter, Anchor::Center, Anchor::BottomCenter => $content->x + ($content->width - $cw) * 0.5,
            Anchor::TopRight, Anchor::CenterRight, Anchor::BottomRight => $content->right() - $cw - $margin->right,
            Anchor::Fill => $content->x + $margin->left,
        };
        $y = match ($anchor) {
            Anchor::TopLeft, Anchor::TopCenter, Anchor::TopRight => $content->y + $margin->top,
            Anchor::CenterLeft, Anchor::Center, Anchor::CenterRight => $content->y + ($content->height - $ch) * 0.5,
            Anchor::BottomLeft, Anchor::BottomCenter, Anchor::BottomRight => $content->bottom() - $ch - $margin->bottom,
            Anchor::Fill => $content->y + $margin->top,
        };
        return [$x, $y];
    }
}
