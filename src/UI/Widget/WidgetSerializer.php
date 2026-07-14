<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\UI\UIStyle;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

/**
 * Round-trips a retained-mode {@see Widget} tree to and from a plain array
 * (JSON-ready). This is the UI counterpart to the ECS scene transpiler: it
 * gives the editor a data representation of a widget hierarchy to load, edit,
 * and save, while PHP composition stays the canonical authoring path.
 *
 * Format (mirrors the component `_class` convention):
 *   { "_widget": <fqcn>, <prop>: <value>, ..., "children": [ ... ] }
 *
 * Only public, persistent properties are serialized. Layout-computed state
 * (bounds, measured size) and tree links (parent/children/listeners) are
 * protected or private and thus never surface here; a small denylist drops the
 * few public runtime-interaction fields (e.g. a button's hover/press flags).
 * A widget's `styleOverride` is not serialized in this first iteration — an
 * unset override is the overwhelmingly common case.
 */
final class WidgetSerializer
{
    /** Public properties that hold transient runtime state, never persisted. */
    private const TRANSIENT = ['hovered', 'pressed', 'focused', 'open', 'scrollOffset'];

    /**
     * Public properties serialized specially, not as literals: bindings/actions
     * become `$bind`/`$on`, and a Repeater's collection/template become
     * `$each`/`template`.
     */
    private const SPECIAL = ['bindings', 'eventBindings', 'each', 'template'];

    /** @var array<class-string, Widget|null> Cached default instances for compaction. */
    private array $defaults = [];

    /**
     * @param  bool  $compact  Omit properties still at their constructor default,
     *                         keeping persisted layouts small. Pass false to emit
     *                         every property (e.g. to derive an editor schema).
     * @return array<string, mixed>
     */
    public function toArray(Widget $widget, bool $compact = true): array
    {
        $result = ['_widget' => $widget::class];
        $default = $compact ? $this->defaultInstance($widget::class) : null;

        foreach ($this->persistentProperties($widget::class) as $prop) {
            if (! $prop->isInitialized($widget)) {
                continue;
            }
            // A bound property is emitted as a `$bind`, not its (stale) literal.
            if (isset($widget->bindings[$prop->getName()])) {
                continue;
            }
            $value = $this->serializeValue($prop->getValue($widget));

            if ($default !== null && $prop->isInitialized($default)) {
                $defaultValue = $this->serializeValue($prop->getValue($default));
                if (json_encode($value) === json_encode($defaultValue)) {
                    continue; // unchanged from the default — omit it
                }
            }

            $result[$prop->getName()] = $value;
        }

        foreach ($widget->bindings as $prop => $path) {
            $result[$prop] = ['$bind' => $path];
        }
        if ($widget->eventBindings !== []) {
            $result['$on'] = $widget->eventBindings;
        }
        if ($widget instanceof Repeater) {
            if ($widget->each !== '') {
                $result['$each'] = $widget->each;
            }
            if ($widget->template !== []) {
                $result['template'] = $widget->template;
            }
        }

        $children = array_map(fn (Widget $c) => $this->toArray($c, $compact), $widget->getChildren());
        if ($children !== []) {
            $result['children'] = $children;
        }

        return $result;
    }

    /**
     * @param  class-string<Widget>  $class
     */
    private function defaultInstance(string $class): ?Widget
    {
        if (! array_key_exists($class, $this->defaults)) {
            $ref = new ReflectionClass($class);
            $this->defaults[$class] = (($ref->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0)
                ? $ref->newInstance()
                : null;
        }

        return $this->defaults[$class];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): Widget
    {
        $class = $data['_widget'] ?? null;
        if (! is_string($class) || ! is_a($class, Widget::class, true)) {
            throw new RuntimeException('Invalid or missing "_widget" class in widget data');
        }

        $widget = $this->instantiate($class);

        // Event/action bindings: { "$on": { "click": "confirmSetup" } }
        if (isset($data['$on']) && is_array($data['$on'])) {
            $on = [];
            foreach ($data['$on'] as $event => $action) {
                if (is_string($event) && is_string($action)) {
                    $on[$event] = $action;
                }
            }
            $widget->eventBindings = $on;
        }

        // Data-driven list: collection path + row template (kept raw, cloned at bind).
        if ($widget instanceof Repeater) {
            if (isset($data['$each']) && is_string($data['$each'])) {
                $widget->each = $data['$each'];
            }
            if (isset($data['template']) && is_array($data['template'])) {
                /** @var array<string, mixed> $template */
                $template = $data['template'];
                $widget->template = $template;
            }
        }

        foreach ($this->persistentProperties($class) as $prop) {
            $key = $prop->getName();
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            // Value binding: { "text": { "$bind": "selectedClient.companyName" } }
            if (is_array($value) && array_key_exists('$bind', $value)) {
                if (is_string($value['$bind'])) {
                    $widget->bindings[$key] = $value['$bind'];
                }

                continue; // no literal for a bound property
            }
            $prop->setValue($widget, $this->deserializeValue($value, $prop->getType()));
        }

        $children = $data['children'] ?? [];
        if (is_array($children)) {
            foreach ($children as $childData) {
                if (is_array($childData)) {
                    /** @var array<string, mixed> $childData */
                    $widget->addChild($this->fromArray($childData));
                }
            }
        }

        return $widget;
    }

    /**
     * Reflection is memoized per class because deserialization is a hot path, not
     * a one-off: a Repeater re-expands its template once per item per frame, so a
     * list of N rows re-enters this code N x (widgets per row) times every frame.
     * The shape of a class cannot change at runtime, so caching it is free.
     *
     * @var array<class-string<Widget>, list<ReflectionProperty>>
     */
    private static array $propertyCache = [];

    /** @var array<class-string<Widget>, ReflectionClass<Widget>> */
    private static array $classCache = [];

    /** @var array<class-string<Widget>, bool> True when the ctor takes no required args. */
    private static array $ctorlessCache = [];

    /**
     * @param class-string<Widget> $class
     * @return ReflectionClass<Widget>
     */
    private function reflect(string $class): ReflectionClass
    {
        return self::$classCache[$class] ??= new ReflectionClass($class);
    }

    /**
     * Public, non-static, non-transient properties of a widget class.
     *
     * @param class-string<Widget> $class
     * @return list<ReflectionProperty>
     */
    private function persistentProperties(string $class): array
    {
        if (isset(self::$propertyCache[$class])) {
            return self::$propertyCache[$class];
        }

        $props = [];
        foreach ($this->reflect($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if ($prop->isStatic()
                || in_array($name, self::TRANSIENT, true)
                || in_array($name, self::SPECIAL, true)
            ) {
                continue;
            }
            $props[] = $prop;
        }

        return self::$propertyCache[$class] = $props;
    }

    /**
     * @param class-string<Widget> $class
     */
    private function instantiate(string $class): Widget
    {
        $ref = $this->reflect($class);

        // Prefer the real constructor so transient state (bounds, sizing,
        // padding, margin) is initialized; fall back for arg-requiring types.
        $ctorless = self::$ctorlessCache[$class]
            ??= ($ref->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0;

        if ($ctorless) {
            /** @var Widget */
            return $ref->newInstance();
        }

        /** @var Widget $widget */
        $widget = $ref->newInstanceWithoutConstructor();
        $widget->setBounds(new Rect);

        return $widget;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof Color || $value instanceof Vec2 || $value instanceof Rect) {
            return $value->toArray();
        }
        if ($value instanceof Sizing) {
            return $this->plainToArray($value);
        }
        if ($value instanceof EdgeInsets) {
            return ['top' => $value->top, 'right' => $value->right, 'bottom' => $value->bottom, 'left' => $value->left];
        }
        if ($value instanceof UIStyle) {
            return $this->plainToArray($value);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->serializeValue($v), $value);
        }

        // Unsupported object type (e.g. a style override) — dropped for now.
        return null;
    }

    private function deserializeValue(mixed $data, ?\ReflectionType $type): mixed
    {
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($data === null) {
            return null;
        }

        return match ($typeName) {
            'int' => (int) (is_numeric($data) ? $data : 0),
            'float' => (float) (is_numeric($data) ? $data : 0),
            'string' => is_scalar($data) ? (string) $data : '',
            'bool' => (bool) $data,
            'array' => is_array($data) ? $data : [],
            Color::class => $this->toColor($data),
            Vec2::class => $this->toVec2($data),
            Rect::class => $this->toRect($data),
            Sizing::class => $this->toSizing($data),
            EdgeInsets::class => $this->toEdgeInsets($data),
            UIStyle::class => $this->toUiStyle($data),
            default => $data,
        };
    }

    private function toUiStyle(mixed $data): UIStyle
    {
        $style = new UIStyle;
        if (! is_array($data)) {
            return $style;
        }
        foreach ((new ReflectionClass($style))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $key = $prop->getName();
            if (array_key_exists($key, $data)) {
                $prop->setValue($style, $this->deserializeValue($data[$key], $prop->getType()));
            }
        }

        return $style;
    }

    /**
     * Reflect a plain value object's public scalar properties into an array.
     *
     * @return array<string, mixed>
     */
    private function plainToArray(object $object): array
    {
        $out = [];
        foreach ((new ReflectionClass($object))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic() && $prop->isInitialized($object)) {
                $out[$prop->getName()] = $this->serializeValue($prop->getValue($object));
            }
        }

        return $out;
    }

    private function toColor(mixed $d): Color
    {
        $a = is_array($d) ? $d : [];

        return new Color($this->f($a['r'] ?? 0), $this->f($a['g'] ?? 0), $this->f($a['b'] ?? 0), $this->f($a['a'] ?? 1));
    }

    private function toVec2(mixed $d): Vec2
    {
        $a = is_array($d) ? $d : [];

        return new Vec2($this->f($a['x'] ?? 0), $this->f($a['y'] ?? 0));
    }

    private function toRect(mixed $d): Rect
    {
        $a = is_array($d) ? $d : [];

        return new Rect($this->f($a['x'] ?? 0), $this->f($a['y'] ?? 0), $this->f($a['width'] ?? 0), $this->f($a['height'] ?? 0));
    }

    private function toSizing(mixed $d): Sizing
    {
        $a = is_array($d) ? $d : [];

        return new Sizing(
            width: $this->f($a['width'] ?? 0),
            height: $this->f($a['height'] ?? 0),
            minWidth: $this->f($a['minWidth'] ?? 0),
            minHeight: $this->f($a['minHeight'] ?? 0),
            maxWidth: $this->f($a['maxWidth'] ?? PHP_FLOAT_MAX),
            maxHeight: $this->f($a['maxHeight'] ?? PHP_FLOAT_MAX),
            fillWidth: (bool) ($a['fillWidth'] ?? false),
            fillHeight: (bool) ($a['fillHeight'] ?? false),
        );
    }

    private function toEdgeInsets(mixed $d): EdgeInsets
    {
        $a = is_array($d) ? $d : [];

        return new EdgeInsets($this->f($a['top'] ?? 0), $this->f($a['right'] ?? 0), $this->f($a['bottom'] ?? 0), $this->f($a['left'] ?? 0));
    }

    private function f(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }
}
