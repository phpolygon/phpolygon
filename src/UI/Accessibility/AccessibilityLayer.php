<?php

declare(strict_types=1);

namespace PHPolygon\UI\Accessibility;

use PHPolygon\Math\Rect;
use PHPolygon\UI\Widget\WidgetTree;

/**
 * One widget tree of a window that draws several per frame (a panel, a dialog on
 * top of it, an overlay). Passed to {@see AccessibilityBridge::syncLayers()} in
 * z-order, lowest first.
 *
 * The name is what keeps the layer's node ids stable: use the same name for the
 * same logical layer every frame (even when the tree object is rebuilt), a different
 * one for each layer that exists at the same time.
 */
final class AccessibilityLayer
{
    /** @var (\Closure(Rect): Rect)|null */
    public readonly ?\Closure $boundsTransform;

    /**
     * @param string $name   stable identity of the layer ("panel", "settings-dialog")
     * @param bool   $modal  a modal layer hides every layer below it from assistive technology
     * @param (callable(Rect): Rect)|null $boundsTransform maps this tree's bounds into the space the
     *        bridge's own transform expects (a tree laid out in a sub-region); applied before it
     * @param string $label  spoken name of the layer ("Settings"); empty: none
     */
    public function __construct(
        public readonly WidgetTree $tree,
        public readonly string $name,
        public readonly bool $modal = false,
        ?callable $boundsTransform = null,
        public readonly string $label = '',
    ) {
        $this->boundsTransform = $boundsTransform === null ? null : \Closure::fromCallable($boundsTransform);
    }
}
