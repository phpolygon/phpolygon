<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use ReflectionNamedType;
use ReflectionProperty;

/**
 * Binds an editor-authored widget tree to a {@see WidgetContext}: resolves value
 * bindings into widget properties, wires two-way write-back for inputs and
 * event/action bindings for controls, and expands {@see Repeater}s over their
 * bound collections.
 *
 * Re-run whenever the bound data may have changed (typically once per frame
 * before the tree renders). {@see bind()} is idempotent: it clears a widget's
 * binding-owned event listeners before re-wiring, so repeated calls never stack
 * duplicate handlers.
 */
final class WidgetBinder
{
    private WidgetSerializer $serializer;

    public function __construct(?WidgetSerializer $serializer = null)
    {
        $this->serializer = $serializer ?? new WidgetSerializer;
    }

    public function bind(Widget $widget, WidgetContext $context): void
    {
        if ($widget instanceof Repeater) {
            $this->expandRepeater($widget, $context);
        }

        $this->applyValueBindings($widget, $context);
        $this->wireEvents($widget, $context);

        // A repeater's rows are bound against their per-item context inside
        // expandRepeater(); everything else inherits this context.
        if (! $widget instanceof Repeater) {
            foreach ($widget->getChildren() as $child) {
                $this->bind($child, $context);
            }
        }
    }

    private function expandRepeater(Repeater $repeater, WidgetContext $context): void
    {
        $collection = $context->get($repeater->each);
        if (! is_iterable($collection)) {
            $repeater->clearChildren();

            return;
        }

        // Materialize once: the count decides whether we can recycle, and a
        // Generator would be exhausted by a second pass.
        $items = is_array($collection) ? $collection : iterator_to_array($collection);
        $rows  = $repeater->getChildren();

        // Deserializing the template once per item per frame is the most expensive
        // thing a data-bound tree does — a 29-row list rebuilt 200+ widgets from
        // scratch on every single frame. But between frames it is almost always
        // only the item DATA that moved: same rows, same shape. In that case reuse
        // the existing widgets and just re-bind them; bind() overwrites every bound
        // property and re-wires listeners from clean, so a recycled row carries no
        // state forward from the previous frame.
        //
        // Rebuild only when the shape actually changed: a different row count, or
        // an authored template swapped underneath us. A transpiled layout supplies
        // a templateFactory whose shape is fixed at codegen time, so the array
        // template-equality check is redundant there — only the count can change.
        $factory = $repeater->templateFactory;
        if (count($rows) !== count($items) || ($factory === null && ! $repeater->rowsMatchTemplate())) {
            $repeater->clearChildren();
            foreach ($items as $_) {
                $repeater->addChild($factory !== null ? ($factory)() : $this->serializer->fromArray($repeater->template));
            }
            $repeater->markRowsBuilt();
            $rows = $repeater->getChildren();
        }

        $i = 0;
        foreach ($items as $item) {
            $this->bind($rows[$i++], new ScopedWidgetContext($item, $context));
        }
    }

    private function applyValueBindings(Widget $widget, WidgetContext $context): void
    {
        foreach ($widget->bindings as $prop => $path) {
            if (! property_exists($widget, $prop)) {
                continue;
            }
            $ref = new ReflectionProperty($widget, $prop);
            $coerced = $this->coerce($context->get($path), $ref);
            $type = $ref->getType();
            if ($coerced === null && $type !== null && ! $type->allowsNull()) {
                continue; // never violate a non-nullable typed property
            }
            $widget->{$prop} = $coerced;
        }
    }

    private function wireEvents(Widget $widget, WidgetContext $context): void
    {
        // Idempotent: drop listeners this binder added on a previous pass.
        $widget->clearEventListeners();

        // Explicit action bindings: event name => context action.
        foreach ($widget->eventBindings as $event => $action) {
            $widget->on($event, static function (mixed ...$args) use ($context, $action): void {
                $context->call($action, array_values($args));
            });
        }

        // Two-way: when an input's value property is bound, write user changes back.
        $valueProp = $this->valueProperty($widget);
        if ($valueProp !== null && isset($widget->bindings[$valueProp])) {
            $path = $widget->bindings[$valueProp];
            $writeBack = static function () use ($widget, $context, $path, $valueProp): void {
                $context->set($path, $widget->{$valueProp});
            };
            $widget->on('change', $writeBack);
            $widget->on('input', $writeBack);
        }
    }

    /** The property an input widget mutates on interaction, or null for non-inputs. */
    private function valueProperty(Widget $widget): ?string
    {
        return match (true) {
            $widget instanceof Slider => 'value',
            $widget instanceof Checkbox => 'checked',
            $widget instanceof Toggle => 'on',
            $widget instanceof TextInput => 'text',
            $widget instanceof Dropdown => 'selectedIndex',
            $widget instanceof TabView => 'selectedIndex',
            default => null,
        };
    }

    /** Coerce a bound value to the widget property's declared scalar type. */
    private function coerce(mixed $value, ReflectionProperty $prop): mixed
    {
        $type = $prop->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        return match ($name) {
            'string' => is_scalar($value) ? (string) $value : '',
            'int' => is_numeric($value) ? (int) $value : 0,
            'float' => is_numeric($value) ? (float) $value : 0.0,
            'bool' => (bool) $value,
            // A property declared `array` must never receive a bare Traversable
            // (would TypeError on assignment). Materialize iterables to a list;
            // pass a real array through untouched.
            'array' => is_array($value) ? $value : (is_iterable($value) ? iterator_to_array($value) : []),
            default => $value, // pass through (nullable objects, arrays, mixed)
        };
    }
}
