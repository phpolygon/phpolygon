<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;

/**
 * Data-driven list widget. At bind time {@see WidgetBinder} expands its
 * {@see $template} subtree once per item in the collection at {@see $each},
 * binding each generated row against that item (see {@see ScopedWidgetContext}).
 * In the editor, with no data bound, it shows nothing (or a few placeholder rows
 * the editor injects); at runtime the game's collection drives the real count.
 *
 * Items stack vertically by default (inherits {@see VBox}); set
 * {@see $horizontal} to lay them left-to-right instead — for icon rows, chips,
 * or any data-driven horizontal strip — while keeping the per-item scope, so a
 * generated button still receives its own item on click.
 *
 *   { "_widget": "Repeater", "$each": "clients",
 *     "template": { "_widget": "Panel", "children": [
 *        { "_widget": "Label", "text": { "$bind": "companyName" } } ] } }
 *
 *   { "_widget": "Repeater", "$each": "icons", "horizontal": true, "spacing": 44,
 *     "template": { "_widget": "Button", "label": { "$bind": "name" } } }
 */
class Repeater extends VBox
{
    /** Binding path (resolved on the bound context) of the collection to iterate. */
    public string $each = '';

    /** @var array<string, mixed> Serialized template subtree, cloned per item. */
    public array $template = [];

    /**
     * Zero-parse row builder. When a transpiled layout sets this, the binder
     * calls it to build each row instead of reflecting {@see $template} once per
     * item per frame. Transient: describes how rows are built, not persisted
     * layout data, so the serializer never emits it.
     *
     * @var (\Closure(): Widget)|null
     */
    public ?\Closure $templateFactory = null;

    /** Lay generated items left-to-right instead of top-to-bottom. */
    public bool $horizontal = false;

    /**
     * Snapshot of the template the current rows were deserialized from, so the
     * binder can tell "same shape, new data" (recycle the rows) from "different
     * shape" (rebuild them). Private, so the serializer never persists it — it
     * describes the live children, not the widget's authored definition.
     *
     * @var array<string, mixed>|null
     */
    private ?array $rowsBuiltFrom = null;

    /** True when the existing children were built from the template in force now. */
    public function rowsMatchTemplate(): bool
    {
        return $this->rowsBuiltFrom !== null && $this->rowsBuiltFrom === $this->template;
    }

    /** Record that the current children were just built from the current template. */
    public function markRowsBuilt(): void
    {
        $this->rowsBuiltFrom = $this->template;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        if (!$this->horizontal) {
            parent::measure($availableWidth, $availableHeight, $style);

            return;
        }

        // Horizontal (HBox-style) measurement of the expanded items.
        $style = $this->resolveStyle($style);
        $contentW = $availableWidth - $this->padding->horizontal();
        $contentH = $availableHeight - $this->padding->vertical();

        $totalWidth = 0.0;
        $maxHeight = 0.0;
        $fillCount = 0;

        foreach ($this->children as $i => $child) {
            if (!$child->visible) {
                continue;
            }
            if ($child->sizing->fillWidth) {
                $fillCount++;
            } else {
                $child->measure($contentW, $contentH, $style);
                $totalWidth += $child->getMeasuredWidth() + $child->margin->horizontal();
            }
            if ($i > 0) {
                $totalWidth += $this->spacing;
            }
        }

        if ($fillCount > 0) {
            $remaining = max(0.0, $contentW - $totalWidth);
            $perChild = $remaining / $fillCount;
            foreach ($this->children as $child) {
                if (!$child->visible || !$child->sizing->fillWidth) {
                    continue;
                }
                $child->measure($perChild - $child->margin->horizontal(), $contentH, $style);
                $totalWidth += $child->getMeasuredWidth() + $child->margin->horizontal();
            }
        }

        foreach ($this->children as $child) {
            if (!$child->visible) {
                continue;
            }
            $childH = $child->getMeasuredHeight() + $child->margin->vertical();
            if ($childH > $maxHeight) {
                $maxHeight = $childH;
            }
        }

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width
                : clamp($totalWidth + $this->padding->horizontal(), $this->sizing->minWidth, $this->sizing->maxWidth));
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height
                : clamp($maxHeight + $this->padding->vertical(), $this->sizing->minHeight, $this->sizing->maxHeight));
    }

    public function layout(UIStyle $style): void
    {
        if (!$this->horizontal) {
            parent::layout($style);

            return;
        }

        $style = $this->resolveStyle($style);
        $content = $this->contentRect();
        $x = $content->x;

        foreach ($this->children as $child) {
            if (!$child->visible) {
                continue;
            }

            $childH = $child->sizing->fillHeight
                ? $content->height - $child->margin->vertical()
                : $child->getMeasuredHeight();

            $childX = $x + $child->margin->left;
            $childY = $content->y + $child->margin->top;

            $child->setBounds(new Rect($childX, $childY, $child->getMeasuredWidth(), $childH));
            $child->layout($style);

            $x = $childX + $child->getMeasuredWidth() + $child->margin->right + $this->spacing;
        }
    }
}
