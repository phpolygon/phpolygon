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
        $repeater->clearChildren();
        $collection = $context->get($repeater->each);
        if (! is_iterable($collection)) {
            return;
        }
        foreach ($collection as $item) {
            $row = $this->serializer->fromArray($repeater->template);
            $this->bind($row, new ScopedWidgetContext($item, $context));
            $repeater->addChild($row);
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
