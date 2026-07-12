<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

/**
 * The seam between an editor-authored widget tree and game logic.
 *
 * A tree loaded from a `*.ui.json` is bound to a WidgetContext — a per-panel
 * "view-model". Value bindings (`{"$bind": "path"}`) read through {@see get()};
 * two-way bindings on inputs write back through {@see set()} when the widget
 * changes; event bindings (`{"$on": {"click": "action"}}`) dispatch through
 * {@see call()}. Structure and look come from the editor; the game supplies only
 * the data and behaviour behind this interface.
 */
interface WidgetContext
{
    /** Resolve a dotted binding path (e.g. "selectedClient.companyName") to a value, or null when absent. */
    public function get(string $path): mixed;

    /** Write a value back to a two-way binding path. No-op when the path is not writable. */
    public function set(string $path, mixed $value): void;

    /**
     * Dispatch a named action, e.g. a bound button click.
     *
     * @param  list<mixed>  $args
     */
    public function call(string $action, array $args = []): void;
}
