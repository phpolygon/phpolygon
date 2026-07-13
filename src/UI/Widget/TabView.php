<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\UI\UIStyle;

/**
 * Tabbed container: a horizontal tab bar (one clickable tab per child) plus a
 * content area that shows only the selected child.
 *
 * Tab titles come from the {@see $tabs} list when provided; otherwise each
 * child's `title` property is used (e.g. a {@see Panel}), falling back to
 * "Tab N". Only the selected child is measured, laid out and drawn as content;
 * every tab button is always drawn.
 *
 * {@see $selectedIndex} is bindable and two-way (like {@see Dropdown}): a click
 * updates it and emits `change`, so a bound panel can persist the active tab.
 *
 *   { "_widget": "PHPolygon\\UI\\Widget\\TabView",
 *     "tabs": ["General", "Advanced"],
 *     "selectedIndex": {"$bind": "activeTab"},
 *     "children": [ ... ] }
 */
class TabView extends Widget
{
    /** Selected tab index (bindable, two-way). */
    public int $selectedIndex = 0;

    /** @var list<string> Explicit tab titles; falls back to child titles when empty. */
    public array $tabs = [];

    /** Height of the tab bar in pixels. */
    public float $tabBarHeight = 28.0;

    /** Horizontal padding inside each tab button. */
    public float $tabPadding = 12.0;

    public function __construct(float $tabBarHeight = 28.0)
    {
        parent::__construct();
        $this->tabBarHeight = $tabBarHeight;
    }

    /** The child currently shown as content, or null when there are none. */
    public function selectedChild(): ?Widget
    {
        $children = $this->getChildren();
        if ($children === []) {
            return null;
        }
        $index = $this->clampedIndex();

        return $children[$index] ?? null;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $contentW = $availableWidth - $this->padding->horizontal();
        $contentH = $availableHeight - $this->padding->vertical() - $this->tabBarHeight;

        $selected = $this->selectedChild();
        $childW = 0.0;
        $childH = 0.0;
        if ($selected !== null) {
            $selected->measure($contentW, $contentH, $style);
            $childW = $selected->getMeasuredWidth() + $selected->margin->horizontal();
            $childH = $selected->getMeasuredHeight() + $selected->margin->vertical();
        }

        // The tab bar must be at least as wide as the sum of tab buttons.
        $barW = 0.0;
        foreach ($this->tabTitles() as $title) {
            $barW += $this->tabWidth($title, $style);
        }

        $naturalW = max($childW, $barW);
        $naturalH = $this->tabBarHeight + $childH;

        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : $naturalW + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : $naturalH + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $selected = $this->selectedChild();
        if ($selected === null) {
            return;
        }

        $content = $this->contentRect();
        $areaY = $content->y + $this->tabBarHeight;
        $areaH = max(0.0, $content->height - $this->tabBarHeight);

        $childW = $selected->sizing->fillWidth
            ? max(0.0, $content->width - $selected->margin->horizontal())
            : $selected->getMeasuredWidth();
        $childH = $selected->sizing->fillHeight
            ? max(0.0, $areaH - $selected->margin->vertical())
            : $selected->getMeasuredHeight();

        $selected->setBounds(new Rect(
            $content->x + $selected->margin->left,
            $areaY + $selected->margin->top,
            $childW,
            $childH,
        ));
        $selected->layout($style);
    }

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        $style = $this->resolveStyle($style);
        $content = $this->contentRect();
        $selectedIndex = $this->clampedIndex();

        // Tab bar background
        $renderer->drawRect($content->x, $content->y, $content->width, $this->tabBarHeight, $style->backgroundColor);

        $x = $content->x;
        foreach ($this->tabTitles() as $i => $title) {
            $tabW = $this->tabWidth($title, $style);
            $active = $i === $selectedIndex;

            // Active tab highlighted with activeColor; inactive tabs blend into
            // the bar background (hoverColor is reserved for button hover fills).
            $bg = $active ? $style->activeColor : $style->backgroundColor;
            $renderer->drawRect($x, $content->y, $tabW, $this->tabBarHeight, $bg);

            $textColor = $active ? $style->textColor : $style->textColor->withAlpha(0.7);
            $renderer->drawText(
                $title,
                $x + $this->tabPadding,
                $content->y + ($this->tabBarHeight - $style->fontSize) * 0.5,
                $style->fontSize,
                $textColor,
            );

            $x += $tabW;
        }

        // Underline separating the bar from the content
        $lineY = $content->y + $this->tabBarHeight - $style->borderWidth;
        $renderer->drawRect($content->x, $lineY, $content->width, $style->borderWidth, $style->borderColor);

        // Only the selected child is drawn as content.
        $this->selectedChild()?->draw($renderer, $style);
    }

    /**
     * Hit test: TabView owns the tab bar directly and only descends into the
     * selected content child, so hidden tabs never capture the mouse.
     */
    public function widgetAt(Vec2 $point): ?Widget
    {
        if (!$this->hitTest($point)) {
            return null;
        }

        // The tab bar belongs to the TabView itself.
        if ($this->tabIndexAt($point) !== null) {
            return $this;
        }

        $selected = $this->selectedChild();
        if ($selected !== null) {
            $hit = $selected->widgetAt($point);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this;
    }

    /** Index of the tab under a screen point, or null when the bar was missed. */
    public function tabIndexAt(Vec2 $point): ?int
    {
        foreach (array_keys($this->tabTitles()) as $i) {
            if ($this->getTabRect($i)->contains($point)) {
                return $i;
            }
        }

        return null;
    }

    /** Screen-space rect of the tab button at a given index (for hit testing). */
    public function getTabRect(int $index): Rect
    {
        $style = $this->styleOverride ?? UIStyle::dark();
        $content = $this->contentRect();

        $x = $content->x;
        foreach ($this->tabTitles() as $i => $title) {
            $tabW = $this->tabWidth($title, $style);
            if ($i === $index) {
                return new Rect($x, $content->y, $tabW, $this->tabBarHeight);
            }
            $x += $tabW;
        }

        return new Rect($x, $content->y, 0.0, $this->tabBarHeight);
    }

    /**
     * Select a tab, clamped to the available range, emitting `change` when it
     * actually moves. Used by the input layer on a tab click.
     */
    public function selectTab(int $index): void
    {
        $count = count($this->tabTitles());
        if ($count === 0) {
            return;
        }
        $index = max(0, min($count - 1, $index));
        if ($index !== $this->selectedIndex) {
            $this->selectedIndex = $index;
            $this->emit('change', $index);
        }
    }

    /** @return list<string> Resolved tab titles (explicit, then child titles, then "Tab N"). */
    private function tabTitles(): array
    {
        $children = $this->getChildren();
        $titles = [];
        foreach ($children as $i => $child) {
            if (isset($this->tabs[$i]) && $this->tabs[$i] !== '') {
                $titles[] = $this->tabs[$i];
            } elseif (property_exists($child, 'title') && is_string($child->title) && $child->title !== '') {
                $titles[] = $child->title;
            } else {
                $titles[] = 'Tab ' . ($i + 1);
            }
        }

        // Allow authoring tabs beyond the child count (editor placeholders).
        for ($i = count($children); $i < count($this->tabs); $i++) {
            if ($this->tabs[$i] !== '') {
                $titles[] = $this->tabs[$i];
            }
        }

        return $titles;
    }

    private function tabWidth(string $title, UIStyle $style): float
    {
        $textW = mb_strlen($title) * $style->fontSize * 0.55;

        return $textW + $this->tabPadding * 2.0;
    }

    private function clampedIndex(): int
    {
        $count = max(1, count($this->getChildren()));

        return max(0, min($count - 1, $this->selectedIndex));
    }
}
