<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

/**
 * The per-item context a {@see Repeater} binds each generated row against.
 *
 * Reads resolve against the row's item first, then fall back to the outer
 * context — so a row template binds `companyName` (item) while still seeing
 * global values. Actions delegate to the outer context with the item injected as
 * the first argument, so a row button (`{"$on": {"click": "selectClient"}}`)
 * reaches the panel's view-model and receives the item it belongs to.
 */
final class ScopedWidgetContext implements WidgetContext
{
    private DataWidgetContext $item;

    public function __construct(
        private mixed $itemValue,
        private WidgetContext $parent,
    ) {
        $this->item = new DataWidgetContext($itemValue);
    }

    public function get(string $path): mixed
    {
        return $this->item->get($path) ?? $this->parent->get($path);
    }

    public function set(string $path, mixed $value): void
    {
        // Row-local two-way is uncommon; route writes to the outer view-model.
        $this->parent->set($path, $value);
    }

    public function call(string $action, array $args = []): void
    {
        $this->parent->call($action, [$this->itemValue, ...$args]);
    }
}
