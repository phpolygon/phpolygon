<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIStyle;

/**
 * Base class for all retained-mode UI widgets.
 *
 * Widgets form a tree. Each widget has:
 *  - Children (ordered list)
 *  - Computed bounds (set by parent's layout pass)
 *  - Size constraints (Sizing)
 *  - Padding (EdgeInsets)
 *  - Visibility and enabled state
 *  - Optional style override
 */
abstract class Widget
{
    /** @var list<Widget> */
    protected array $children = [];
    protected ?Widget $parent = null;

    /** Computed screen-space bounds (set during layout) */
    protected Rect $bounds;

    /** Computed content size after measure() */
    protected float $measuredWidth = 0.0;
    protected float $measuredHeight = 0.0;

    public Sizing $sizing;
    public EdgeInsets $padding;
    public EdgeInsets $margin;
    public bool $visible = true;
    public bool $enabled = true;
    public ?UIStyle $styleOverride = null;

    /**
     * Optional hover tooltip. When non-empty, {@see WidgetTree} renders it near
     * the cursor while this widget is hovered (word-wrapped, first line as a
     * heading). Bind it per element (e.g. a metric label) to explain a value.
     */
    public string $tooltip = '';

    /**
     * Anchor within a parent {@see Stack} as an {@see Anchor} value string
     * (e.g. 'bottom_left', 'top_right'). Lets a declarative layout pin an
     * overlay child to a corner without the imperative addAnchored() call.
     * Empty falls back to the Stack's default (top-left).
     */
    public string $stackAnchor = '';

    /**
     * Accessible name read by a screen reader. Empty falls back to the widget's
     * own text (a button's label, a label's text) and then to the first line of
     * the tooltip. Set it on widgets that carry no text of their own, such as an
     * icon-only or flat card-overlay button.
     */
    public string $accessibleLabel = '';

    /** Longer accessible description (what the control does). Empty falls back to the tooltip. */
    public string $accessibleDescription = '';

    /**
     * Explicit accessibility id and keyboard-focus key. Empty derives one from the
     * widget's position in the tree. Set it when a widget keeps its identity while
     * its siblings are inserted or removed (a stable id avoids resending the node,
     * and keeps keyboard focus on it across rebuilds).
     */
    public string $accessibleId = '';

    /**
     * Keyboard focusability: null follows the widget type (buttons, toggles,
     * sliders, dropdowns, text fields and tab bars are focusable), true or false
     * forces it either way.
     */
    public ?bool $focusable = null;

    /**
     * Keyboard focus order override, like HTML tabindex: widgets with a value
     * above 0 come first in ascending order, then the rest (0) in document order.
     */
    public int $focusOrder = 0;

    /**
     * Editor-authored value bindings: widget property name => context path.
     * Resolved by {@see WidgetBinder} against a {@see WidgetContext}; two-way for
     * input widgets. Not persisted as a literal — the serializer emits each as
     * `<prop>: {"$bind": <path>}`.
     *
     * @var array<string, string>
     */
    public array $bindings = [];

    /**
     * Editor-authored event bindings: event name (e.g. 'click', 'change') =>
     * context action. Serialized as `"$on": { <event>: <action> }`.
     *
     * @var array<string, string>
     */
    public array $eventBindings = [];

    /** @var array<string, list<callable>> */
    private array $eventListeners = [];

    /**
     * Clip rects pushed by clipping containers (see {@see ScrollView}).
     *
     * A clipping container only ever has a handful of direct children — usually
     * one layout box — so testing those against the viewport culls nothing: that
     * single child spans the full content height and always intersects. The rows
     * worth skipping sit one level deeper, inside that box. Container widgets
     * therefore consult the innermost clip rect via {@see isClipped()} and skip
     * children that fall entirely outside it, however deeply nested they are.
     *
     * Draw-only. measure() and layout() must still visit every child, or a
     * ScrollView cannot know its content height or size its scrollbar.
     *
     * @var list<Rect>
     */
    private static array $clipStack = [];

    public function __construct()
    {
        $this->bounds = new Rect();
        $this->sizing = Sizing::wrap();
        $this->padding = EdgeInsets::zero();
        $this->margin = EdgeInsets::zero();
    }

    // ── Draw-time clipping ───────────────────────────────────────

    /** Push a clip rect. Must be paired with {@see popClip()} via try/finally. */
    protected static function pushClip(Rect $rect): void
    {
        self::$clipStack[] = $rect;
    }

    protected static function popClip(): void
    {
        array_pop(self::$clipStack);
    }

    /**
     * True when $child lies entirely outside the innermost clip rect, i.e. it
     * would be scissored away anyway and drawing it is pure waste. False when
     * nothing is clipping (no container pushed a rect).
     */
    protected static function isClipped(Widget $child): bool
    {
        $clip = end(self::$clipStack);
        if ($clip === false) {
            return false;
        }
        return !$clip->intersects($child->bounds);
    }

    // ── Tree operations ──────────────────────────────────────────

    public function addChild(Widget $child): static
    {
        $child->parent = $this;
        $this->children[] = $child;
        return $this;
    }

    public function removeChild(Widget $child): static
    {
        $this->children = array_values(array_filter(
            $this->children,
            fn(Widget $c) => $c !== $child,
        ));
        $child->parent = null;
        return $this;
    }

    public function clearChildren(): static
    {
        foreach ($this->children as $child) {
            $child->parent = null;
        }
        $this->children = [];
        return $this;
    }

    /** @return list<Widget> */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getParent(): ?Widget
    {
        return $this->parent;
    }

    // ── Layout ───────────────────────────────────────────────────

    /**
     * Measure the widget's desired size given available space.
     * Sets $measuredWidth and $measuredHeight.
     */
    abstract public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void;

    /**
     * Position children within the computed bounds.
     */
    abstract public function layout(UIStyle $style): void;

    /**
     * Render this widget and its children.
     */
    abstract public function draw(Renderer2DInterface $renderer, UIStyle $style): void;

    public function getBounds(): Rect
    {
        return $this->bounds;
    }

    public function setBounds(Rect $bounds): void
    {
        $this->bounds = $bounds;
    }

    public function getMeasuredWidth(): float
    {
        return $this->measuredWidth;
    }

    public function getMeasuredHeight(): float
    {
        return $this->measuredHeight;
    }

    /**
     * Resolve effective style: widget override → inherited → global.
     */
    public function resolveStyle(UIStyle $inherited): UIStyle
    {
        return $this->styleOverride ?? $inherited;
    }

    /**
     * Room this container has to offer its children along one axis, at measure
     * time — the measure-time counterpart of {@see contentRect()}.
     *
     * A container with an explicit size (or a maximum) on that axis is making a
     * promise about how much room its children get. Handing them the parent's
     * available extent instead breaks it: a fill child claims the whole
     * remaining viewport and every sibling after it is laid out beyond the
     * container's own bounds — drawn outside the box and unreachable by the
     * pointer. The explicit size still cannot exceed what the parent can
     * actually give, so it is clamped to the available extent.
     *
     * @param  float  $fixed  the sizing's explicit extent on this axis (0 = none)
     * @param  float  $max  the sizing's maximum extent on this axis
     * @param  bool  $fill  whether the sizing fills the parent on this axis
     * @param  float  $available  the extent the parent is offering
     * @param  float  $padding  this widget's padding along the axis
     */
    protected function innerExtent(
        float $fixed,
        float $max,
        bool $fill,
        float $available,
        float $padding,
    ): float {
        $extent = $available;
        if (! $fill) {
            if ($fixed > 0.0) {
                $extent = min($fixed, $available);
            } elseif ($max < PHP_FLOAT_MAX) {
                $extent = min($max, $available);
            }
        }

        return max(0.0, $extent - $padding);
    }

    /**
     * Return the content area (bounds minus padding).
     */
    public function contentRect(): Rect
    {
        return new Rect(
            $this->bounds->x + $this->padding->left,
            $this->bounds->y + $this->padding->top,
            max(0.0, $this->bounds->width - $this->padding->horizontal()),
            max(0.0, $this->bounds->height - $this->padding->vertical()),
        );
    }

    // ── Keyboard focus ───────────────────────────────────────────

    /** Whether this widget type takes keyboard focus unless {@see $focusable} says otherwise. */
    protected function focusableByDefault(): bool
    {
        return false;
    }

    /** Can keyboard focus land on this widget right now? */
    public function isFocusable(): bool
    {
        return $this->visible && $this->enabled && ($this->focusable ?? $this->focusableByDefault());
    }

    /**
     * The children keyboard traversal and the accessibility tree descend into.
     * A container that shows only some of its children (a tab view) narrows this.
     *
     * @return list<Widget>
     */
    public function getFocusChildren(): array
    {
        return $this->children;
    }

    /** The area the focus ring surrounds; defaults to the whole widget. */
    public function focusRect(): Rect
    {
        return $this->bounds;
    }

    // ── Events ───────────────────────────────────────────────────

    public function on(string $event, callable $listener): static
    {
        $this->eventListeners[$event][] = $listener;
        return $this;
    }

    public function emit(string $event, mixed ...$args): void
    {
        foreach ($this->eventListeners[$event] ?? [] as $listener) {
            $listener(...$args);
        }
    }

    /**
     * Drop all event listeners. Used by {@see WidgetBinder} before re-wiring a
     * bound widget so repeated binds never stack duplicate handlers.
     */
    public function clearEventListeners(): void
    {
        $this->eventListeners = [];
    }

    /**
     * Hit test: does a screen point land on this widget?
     */
    public function hitTest(Vec2 $point): bool
    {
        return $this->visible && $this->bounds->contains($point);
    }

    /**
     * Find the deepest visible widget at a point (depth-first, last child wins).
     */
    public function widgetAt(Vec2 $point): ?Widget
    {
        if (!$this->hitTest($point)) {
            return null;
        }

        // Check children back-to-front
        for ($i = count($this->children) - 1; $i >= 0; $i--) {
            $hit = $this->children[$i]->widgetAt($point);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this;
    }

    // ── Fluent setters ───────────────────────────────────────────

    public function size(Sizing $sizing): static
    {
        $this->sizing = $sizing;
        return $this;
    }

    public function pad(EdgeInsets $padding): static
    {
        $this->padding = $padding;
        return $this;
    }

    public function margins(EdgeInsets $margin): static
    {
        $this->margin = $margin;
        return $this;
    }

    public function hide(): static
    {
        $this->visible = false;
        return $this;
    }

    public function show(): static
    {
        $this->visible = true;
        return $this;
    }

    public function disable(): static
    {
        $this->enabled = false;
        return $this;
    }

    public function enable(): static
    {
        $this->enabled = true;
        return $this;
    }
}
