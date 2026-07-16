<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\UI\UIStyle;

class Dropdown extends Widget
{
    public string $label;
    /** @var list<string> */
    public array $options;
    public int $selectedIndex;
    public bool $open = false;

    /**
     * Scroll offset of the open option list, in pixels. A list taller than its
     * clamped viewport window ({@see $maxListHeight}) scrolls instead of running
     * off the bottom of the screen.
     */
    public float $listScrollY = 0.0;

    /**
     * Upper bound (px) on the open list's on-screen height, so it never spills
     * past the viewport. 0 = unbounded (the list draws its full height). Set each
     * frame by {@see clampListToViewport()} from the host's viewport height.
     */
    public float $maxListHeight = 0.0;

    /**
     * The style the widget was last measured/drawn with. Hit-test geometry
     * ({@see getOptionRect}, {@see listBounds}) has no style argument, so it reads
     * this to stay pixel-identical to what {@see drawOpenList} rendered — using a
     * hard-coded fallback here would desync clickable rows from drawn rows when
     * the host theme's font size differs from the default.
     */
    private ?UIStyle $lastStyle = null;

    /**
     * @param list<string> $options
     */
    public function __construct(string $label = '', array $options = [], int $selectedIndex = 0)
    {
        parent::__construct();
        $this->label = $label;
        $this->options = $options;
        $this->selectedIndex = $selectedIndex;
        $this->padding = EdgeInsets::symmetric(horizontal: 8.0, vertical: 6.0);
    }

    public function getSelectedValue(): ?string
    {
        return $this->options[$this->selectedIndex] ?? null;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $this->lastStyle = $style;
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $rowH = $style->fontSize + $this->padding->vertical();

        $maxTextW = 0.0;
        foreach ($this->options as $opt) {
            $w = mb_strlen($opt) * $style->fontSize * 0.55;
            if ($w > $maxTextW) $maxTextW = $w;
        }

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width
                : $maxTextW + $this->padding->horizontal() + 20.0); // 20px for arrow
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $labelH + $rowH);
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $this->lastStyle = $style;
        $b = $this->bounds;

        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $fieldY = $b->y + $labelH;
        $fieldH = $b->height - $labelH;

        // All dropdown text is left/top anchored; set it once (the renderer's
        // align is sticky global state shared with every other widget).
        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);

        // Label
        if ($this->label !== '') {
            $renderer->drawText($this->label, $b->x, $b->y, $style->fontSize, $style->textColor);
        }

        // Main box
        $renderer->drawRoundedRect($b->x, $fieldY, $b->width, $fieldH, $style->borderRadius, $style->backgroundColor);
        $renderer->drawRectOutline($b->x, $fieldY, $b->width, $fieldH, $style->borderColor, $style->borderWidth);

        // Selected text
        $selected = $this->getSelectedValue() ?? '';
        $renderer->drawText($selected, $b->x + $this->padding->left, $fieldY + $this->padding->top, $style->fontSize, $style->textColor);

        // Arrow indicator
        $arrowX = $b->right() - 14.0;
        $arrowY = $fieldY + $fieldH * 0.5 - $style->fontSize * 0.25;
        $renderer->drawText($this->open ? '^' : 'v', $arrowX, $arrowY, $style->fontSize * 0.7, $style->textColor);

        // The open list is NOT drawn here: it must float above any following
        // sibling (a ScrollView, a card list) instead of being painted over by
        // them. WidgetTree draws it in a final top-most overlay pass via
        // drawOpenList(), the same way tooltips float above the tree.
    }

    /**
     * Draw the expanded option list. Called by {@see WidgetTree} in a top-most
     * overlay pass (after the whole tree) so the list is never covered by later
     * siblings. No-op unless the dropdown is open.
     *
     * The list is clamped to {@see $maxListHeight} and clipped to that window;
     * a taller option set scrolls (via {@see $listScrollY}) with a scrollbar,
     * rather than running off the bottom of the screen unreachably.
     */
    public function drawOpenList(Renderer2DInterface $renderer, UIStyle $style): void
    {
        if (!$this->open) {
            return;
        }

        $style = $this->resolveStyle($style);
        $this->lastStyle = $style;
        $b = $this->bounds;

        $renderer->setTextAlign(TextAlign::LEFT | TextAlign::TOP);

        $listY = $this->listTopY();
        $rowH = $this->rowHeight();
        $visibleH = $this->visibleListHeight();

        $renderer->drawRoundedRect($b->x, $listY, $b->width, $visibleH, $style->borderRadius, $style->backgroundColor);
        $renderer->drawRectOutline($b->x, $listY, $b->width, $visibleH, $style->borderColor, $style->borderWidth);

        // Clip to the visible window so scrolled-away rows never bleed past it.
        $renderer->pushScissor($b->x, $listY, $b->width, $visibleH);
        try {
            foreach ($this->options as $i => $opt) {
                $optY = $listY + $i * $rowH - $this->listScrollY;
                if ($optY + $rowH < $listY || $optY > $listY + $visibleH) {
                    continue; // fully outside the clip window — skip
                }
                if ($i === $this->selectedIndex) {
                    $renderer->drawRect($b->x + 1.0, $optY, $b->width - 2.0, $rowH, $style->accentColor->withAlpha(0.3));
                }
                $renderer->drawText($opt, $b->x + $this->padding->left, $optY + $this->padding->top, $style->fontSize, $style->textColor);
            }
        } finally {
            $renderer->popScissor();
        }

        if ($this->maxListScroll() > 0.0) {
            $this->drawListScrollBar($renderer, $style, $listY, $visibleH);
        }
    }

    private function drawListScrollBar(Renderer2DInterface $renderer, UIStyle $style, float $listY, float $visibleH): void
    {
        $full = $this->fullListHeight();
        $barW = 6.0;
        $barH = max(20.0, $visibleH * ($visibleH / $full));
        $maxScroll = $this->maxListScroll();
        $barY = $listY + ($maxScroll > 0.0 ? ($visibleH - $barH) * ($this->listScrollY / $maxScroll) : 0.0);
        $barX = $this->bounds->right() - $barW - 2.0;
        $renderer->drawRoundedRect($barX, $barY, $barW, $barH, $barW * 0.5, $style->borderColor->withAlpha(0.4));
    }

    /**
     * Clamp the open list to the host viewport so it becomes a scrollable window
     * instead of overflowing the screen. Call once per frame (after layout) from
     * the host for each open dropdown, passing the viewport height.
     */
    public function clampListToViewport(float $viewportHeight): void
    {
        $bottomMargin = 8.0;
        $available = $viewportHeight - $this->listTopY() - $bottomMargin;
        // Always leave room for at least one row so the list never collapses.
        $this->maxListHeight = max($this->rowHeight(), $available);
        // A shrunk window (or list) may leave the offset out of range.
        $this->listScrollY = max(0.0, min($this->listScrollY, $this->maxListScroll()));
    }

    /** Scroll the open list by $delta px, clamped to its valid range. */
    public function scrollListBy(float $delta): void
    {
        $this->listScrollY = max(0.0, min($this->listScrollY + $delta, $this->maxListScroll()));
    }

    /** Height of one option row. */
    private function rowHeight(): float
    {
        return $this->metricsStyle()->fontSize + $this->padding->vertical();
    }

    /** Absolute Y where the (unscrolled) option list begins, just below the field. */
    private function listTopY(): float
    {
        $style = $this->metricsStyle();
        $labelH = $this->label !== '' ? $style->fontSize + 4.0 : 0.0;
        $fieldH = $this->bounds->height - $labelH;
        return $this->bounds->y + $labelH + $fieldH + 2.0;
    }

    /** Total height of all option rows, ignoring the viewport clamp. */
    private function fullListHeight(): float
    {
        return $this->rowHeight() * count($this->options);
    }

    /** On-screen height of the list window (full height, or the clamp if smaller). */
    public function visibleListHeight(): float
    {
        $full = $this->fullListHeight();
        if ($this->maxListHeight > 0.0 && $this->maxListHeight < $full) {
            // Snap to whole rows so no half-row peeks past the clip edge.
            $rows = max(1, (int) floor($this->maxListHeight / $this->rowHeight()));
            return $rows * $this->rowHeight();
        }
        return $full;
    }

    /** Maximum scroll offset (0 when the whole list fits its window). */
    public function maxListScroll(): float
    {
        return max(0.0, $this->fullListHeight() - $this->visibleListHeight());
    }

    private function metricsStyle(): UIStyle
    {
        return $this->lastStyle ?? UIStyle::dark();
    }

    /**
     * Bounding rect of the visible option-list window (for hit-testing the
     * floating list, which lives outside this widget's own bounds). Null when
     * the dropdown is closed or has no options.
     */
    public function listBounds(): ?Rect
    {
        if (!$this->open || $this->options === []) {
            return null;
        }

        return new Rect(
            $this->bounds->x,
            $this->listTopY(),
            $this->bounds->width,
            $this->visibleListHeight(),
        );
    }

    /**
     * Get the option rect at a given index (for hit testing the dropdown list).
     * Offset by the current scroll so it lines up with the drawn row; a row
     * scrolled outside {@see listBounds()} simply won't contain any cursor that
     * is itself inside the (clipped) window.
     */
    public function getOptionRect(int $index): Rect
    {
        return new Rect(
            $this->bounds->x,
            $this->listTopY() + $index * $this->rowHeight() - $this->listScrollY,
            $this->bounds->width,
            $this->rowHeight(),
        );
    }
}
