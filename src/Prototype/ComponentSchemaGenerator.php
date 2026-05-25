<?php

declare(strict_types=1);

namespace PHPolygon\Prototype;

use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionType;
use Throwable;

/**
 * Reflects #[Serializable] components into a JSON-friendly schema that the
 * WebGL/JSX prototyping front-end consumes to generate its typed component
 * vocabulary (TypeScript types + TresJS shims).
 *
 * The schema is the single source of truth for which props each
 * `<Entity>`-child component accepts. It is *derived from PHP*, never
 * hand-maintained, so the JSX vocabulary cannot drift from the engine's
 * component set: add a #[Property] in PHP and it shows up in the playground
 * after the next `prototype:export`.
 *
 * Pure reflection - no GPU, no engine boot. Safe to run headless / in CI.
 *
 * Output shape:
 * ```
 * {
 *   "_version": 1,
 *   "components": {
 *     "Transform3D": {
 *       "class": "PHPolygon\\Component\\Transform3D",
 *       "category": "Core",
 *       "properties": [
 *         { "name": "position", "type": "Vec3", "phpType": "...", "editorHint": "vec3" },
 *         { "name": "rotation", "type": "Quaternion", "phpType": "...", "editorHint": "quaternion" },
 *         { "name": "parentEntityId", "type": "int", "nullable": true }
 *       ],
 *       "defaults": { "position": {"x":0,"y":0,"z":0}, ... }
 *     }
 *   }
 * }
 * ```
 */
final class ComponentSchemaGenerator
{
    public const VERSION = 1;

    /**
     * Short names of engine value types the front-end maps to dedicated
     * editor widgets / TS types. Anything else with a class type is reported
     * as `object` (nested serializable) so the front-end can recurse.
     */
    private const VALUE_TYPES = ['Vec2', 'Vec3', 'Vec4', 'Quaternion', 'Mat3', 'Mat4', 'Color', 'Rect'];

    public function __construct(
        private readonly AttributeSerializer $serializer = new AttributeSerializer(),
    ) {}

    /**
     * @param list<class-string> $componentClasses
     * @return array<string, mixed>
     */
    public function generate(array $componentClasses): array
    {
        $components = [];
        foreach ($componentClasses as $class) {
            $entry = $this->generateOne($class);
            if ($entry !== null) {
                $components[$this->shortName($class)] = $entry;
            }
        }
        ksort($components);

        return [
            '_version' => self::VERSION,
            'components' => $components,
        ];
    }

    /**
     * Schema for a single component class, or null when the class is not
     * marked #[Serializable].
     *
     * @param class-string $class
     * @return array<string, mixed>|null
     */
    public function generateOne(string $class): ?array
    {
        $ref = new ReflectionClass($class);
        if ($ref->getAttributes(Serializable::class) === []) {
            return null;
        }

        $properties = [];
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(Hidden::class) !== []) {
                continue;
            }
            $propAttrs = $prop->getAttributes(Property::class);
            if ($propAttrs === []) {
                continue;
            }
            $propAttr = $propAttrs[0]->newInstance();

            $entry = ['name' => $propAttr->name ?? $prop->getName()];
            $entry += $this->describeType($prop->getType());

            if ($propAttr->editorHint !== null) {
                $entry['editorHint'] = $propAttr->editorHint;
            }
            if ($propAttr->description !== null) {
                $entry['description'] = $propAttr->description;
            }

            $rangeAttrs = $prop->getAttributes(Range::class);
            if ($rangeAttrs !== []) {
                $range = $rangeAttrs[0]->newInstance();
                $entry['range'] = ['min' => $range->min, 'max' => $range->max];
            }

            $properties[] = $entry;
        }

        return array_filter([
            'class' => $class,
            'category' => $this->category($ref),
            'properties' => $properties,
            'defaults' => $this->defaults($ref),
        ], static fn(mixed $v): bool => $v !== null);
    }

    /** @param ReflectionClass<object> $ref */
    private function category(ReflectionClass $ref): string
    {
        $attrs = $ref->getAttributes(Category::class);
        return $attrs === [] ? 'Uncategorized' : $attrs[0]->newInstance()->name;
    }

    /**
     * Normalise a reflected property type into a JSON-friendly descriptor:
     * builtin scalar names verbatim, engine value types by short name, backed
     * enums with their case values, everything else as `object`.
     *
     * @return array<string, mixed>
     */
    private function describeType(?ReflectionType $type): array
    {
        if (!$type instanceof ReflectionNamedType) {
            return ['type' => 'mixed'];
        }

        $name = $type->getName();
        $out = [];
        if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
            $out['nullable'] = true;
        }

        if ($type->isBuiltin()) {
            $out['type'] = $name; // int | float | string | bool | array | mixed
            return $out;
        }

        if (enum_exists($name)) {
            $out['type'] = 'enum';
            $out['phpType'] = $name;
            $out['enum'] = $this->enumValues($name);
            return $out;
        }

        $short = $this->shortName($name);
        $out['type'] = in_array($short, self::VALUE_TYPES, true) ? $short : 'object';
        $out['phpType'] = $name;
        return $out;
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     * @return list<int|string>
     */
    private function enumValues(string $enumClass): array
    {
        $ref = new ReflectionEnum($enumClass);
        $values = [];
        foreach ($ref->getCases() as $case) {
            if ($case instanceof ReflectionEnumBackedCase) {
                $values[] = $case->getBackingValue();
            } else {
                $values[] = $case->getName();
            }
        }
        return $values;
    }

    /**
     * Default prop values, obtained by no-arg constructing the component and
     * serialising it. Returns null when the component requires constructor
     * arguments (so the front-end falls back to its own type defaults).
     *
     * @param ReflectionClass<object> $ref
     * @return array<string, mixed>|null
     */
    private function defaults(ReflectionClass $ref): ?array
    {
        $ctor = $ref->getConstructor();
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                if (!$param->isOptional()) {
                    return null;
                }
            }
        }

        try {
            $instance = $ref->newInstance();
            $data = $this->serializer->toArray($instance);
        } catch (Throwable) {
            return null;
        }

        unset($data['_class']);
        return $data;
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
