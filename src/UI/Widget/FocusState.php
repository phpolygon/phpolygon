<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

/**
 * Keyboard focus that outlives a {@see WidgetTree}.
 *
 * A host often builds a fresh tree every frame (and a repeater rebuilds its
 * rows), so the tree alone cannot remember which widget has focus. This object
 * is held by the host and handed to each tree; it keeps the focused widget and
 * its focus key (see {@see WidgetTree::focusKeyOf()}), and the tree looks the
 * widget up again by key when the instance is gone.
 *
 * One state per tree (or per sequence of rebuilt versions of the same tree):
 * two trees with different roots sharing a state would each fail to find the
 * other's widget and clear it.
 */
final class FocusState
{
    /** The widget that had focus when it was last set. */
    public ?Widget $widget = null;

    /** Position- or id-based key of that widget; stable across rebuilds. */
    public string $key = '';

    /** True when focus came from the keyboard: only then the ring is drawn. */
    public bool $visible = false;

    public function clear(): void
    {
        $this->widget = null;
        $this->key = '';
        $this->visible = false;
    }
}
