<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\UI\UIStyle;

/**
 * An escape hatch that runs imperative drawing inside the declarative tree.
 *
 * The Canvas (a.k.a. custom-draw) widget occupies a normal sizing box in layout
 * and, during {@see draw()}, hands its laid-out content rect to a draw callback
 * resolved from the bound view-model. This is the pragmatic way to host a chart,
 * a Gantt strip, a spatial/hit-tested viewport, or any bespoke visual inside an
 * otherwise data-driven panel — the panel keeps a declarative shell and drops
 * into raw {@see Renderer2DInterface} calls only where it needs to.
 *
 * ── Callback contract ────────────────────────────────────────────────────────
 * The bound value is a callable with the signature:
 *
 *   callable(Renderer2DInterface $r, Rect $bounds, ?InputInterface $input): void
 *
 *   - $r      the active 2D renderer (same one driving the rest of the tree)
 *   - $bounds the widget's content rect (bounds minus padding), in screen space
 *   - $input  the tree's InputInterface if the host threaded one in via
 *             {@see setInput()}, else null. Draw-time input is optional because
 *             {@see WidgetTree} does not thread input into draw(); a panel that
 *             needs hit-testing calls setInput() before update() each frame.
 *
 * The callback must not begin/end frames or push its own scissor expecting the
 * tree to balance it — it draws within the frame the tree already opened.
 *
 * ── Binding the callable ─────────────────────────────────────────────────────
 * A raw callable cannot be authored as a JSON literal, so it is always supplied
 * by the view-model and wired through a normal value binding. Bind the widget's
 * `drawFn` property to a context path whose value is the callable:
 *
 * ```json
 * {
 *   "_widget": "PHPolygon\\UI\\Widget\\Canvas",
 *   "drawFn":  {"$bind": "renderGantt"},
 *   "sizing":  {"fillWidth": true, "height": 240}
 * }
 * ```
 *
 * ```php
 * $vm = new class {
 *     public \Closure $renderGantt;
 *     public function __construct() {
 *         $this->renderGantt = function (Renderer2DInterface $r, Rect $b): void {
 *             $r->drawRect($b->x, $b->y, $b->width, $b->height, Color::white());
 *         };
 *     }
 * };
 * $tree->bind(new DataWidgetContext($vm));
 * ```
 *
 * {@see DataWidgetContext} resolves the path to a public property (or a
 * zero-arg getter) and {@see WidgetBinder} assigns it to `drawFn` unchanged —
 * `drawFn` is `mixed`, so the binder's pass-through coercion leaves the closure
 * intact. Non-callable bound values are ignored at draw time.
 */
class Canvas extends Widget
{
    /**
     * The imperative draw callback, resolved from the bound context.
     *
     * Typed `mixed` (not `callable`) because PHP forbids `callable` typed
     * properties and because {@see WidgetBinder} must be able to assign whatever
     * the view-model path yields without coercion. Only invoked when callable.
     */
    public mixed $drawFn = null;

    /** Threaded in by the host so the callback can hit-test; null when unset. */
    private ?InputInterface $input = null;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Provide the input source the callback receives. A host that wants the
     * Canvas content to be interactive calls this each frame before the tree's
     * update()/draw(), since WidgetTree does not thread input into draw().
     */
    public function setInput(?InputInterface $input): void
    {
        $this->input = $input;
    }

    public function measure(float $availableWidth, float $availableHeight, UIStyle $style): void
    {
        // Occupy the sizing box: fill, fixed, or a sensible default when wrapping
        // (a custom-draw region has no intrinsic content size to shrink to).
        $this->measuredWidth = $this->sizing->fillWidth ? $availableWidth
            : ($this->sizing->width > 0 ? $this->sizing->width : 100.0 + $this->padding->horizontal());
        $this->measuredHeight = $this->sizing->fillHeight ? $availableHeight
            : ($this->sizing->height > 0 ? $this->sizing->height : 100.0 + $this->padding->vertical());
    }

    public function layout(UIStyle $style): void {}

    public function draw(Renderer2DInterface $renderer, UIStyle $style): void
    {
        if (is_callable($this->drawFn)) {
            ($this->drawFn)($renderer, $this->contentRect(), $this->input);
        }
    }
}
