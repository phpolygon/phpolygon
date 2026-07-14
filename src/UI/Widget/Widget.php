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
