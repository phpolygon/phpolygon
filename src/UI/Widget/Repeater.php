<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

/**
 * Data-driven list widget. At bind time {@see WidgetBinder} expands its
 * {@see $template} subtree once per item in the collection at {@see $each},
 * binding each generated row against that item (see {@see ScopedWidgetContext}).
 * In the editor, with no data bound, it shows nothing (or a few placeholder rows
 * the editor injects); at runtime the game's collection drives the real count.
 *
 * Rows stack vertically (inherits {@see VBox}).
 *
 *   { "_widget": "Repeater", "$each": "clients",
 *     "template": { "_widget": "Panel", "children": [
 *        { "_widget": "Label", "text": { "$bind": "companyName" } } ] } }
 */
class Repeater extends VBox
{
    /** Binding path (resolved on the bound context) of the collection to iterate. */
    public string $each = '';

    /** @var array<string, mixed> Serialized template subtree, cloned per item. */
    public array $template = [];
}
