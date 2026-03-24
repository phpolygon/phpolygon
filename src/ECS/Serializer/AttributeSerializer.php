<?php

declare(strict_types=1);

namespace PHPolygon\ECS\Serializer;

use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

class AttributeSerializer implements SerializerInterface
{
    /** @var array<class-string, list<array{property: ReflectionProperty, attribute: Property}>> */
    private array $cache = [];

    public function toArray(object $object): array
    {
        $class = new ReflectionClass($object);
        $this->assertSerializable($class);
        $properties = $this->getSerializableProperties($class);

        $result = ['_class' => $class->getName()];

        foreach ($properties as ['property' => $prop, 'attribute' => $attr]) {
            if (!$prop->isInitialized($object)) {
                continue;
            }

            $value = $prop->getValue($object);
            $key = $attr->name ?? $prop->getName();
            $result[$key] = $this->serializeValue($value);
        }

        return $result;
    }

    public function fromArray(array $data, string $className): object
    {
        $class = new ReflectionClass($className);
        $this->assertSerializable($class);
        $properties = $this->getSerializableProperties($class);

        $object = $class->newInstanceWithoutConstructor();

        foreach ($properties as ['property' => $prop, 'attribute' => $attr]) {
            $key = $attr->name ?? $prop->getName();
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $type = $prop->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $value = $this->deserializeValue($data[$key], $typeName, $type?->allowsNull() ?? true);
            $prop->setValue($object, $value);
        }

        return $object;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof Vec2) {
            return $value->toArray();
        }

        if ($value instanceof Vec3) {
            return $value->toArray();
        }

        if ($value instanceof Rect) {
            return $value->toArray();
        }

        if ($value instanceof Color) {
            return $value->toArray();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            $class = new ReflectionClass($value);
            if (!empty($class->getAttributes(Serializable::class))) {
                return $this->toArray($value);
            }
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->serializeValue($v), $value);
        }

        return null;
    }

    private function deserializeValue(mixed $data, ?string $typeName, bool $nullable): mixed
    {
        if ($data === null && $nullable) {
            return null;
        }

        if ($typeName === null || is_scalar($data) && in_array($typeName, ['int', 'float', 'string', 'bool'], true)) {
            return match ($typeName) {
                'int' => (int)(is_scalar($data) ? $data : 0),
                'float' => (float)(is_scalar($data) ? $data : 0.0),
                'string' => (string)(is_scalar($data) ? $data : ''),
                'bool' => (bool)$data,
                default => $data,
            };
        }

        if (is_array($data)) {
            /** @var array<string, mixed> $data */
            return match ($typeName) {
                Vec2::class => new Vec2($this->toFloat($data['x'] ?? 0), $this->toFloat($data['y'] ?? 0)),
                Vec3::class => new Vec3($this->toFloat($data['x'] ?? 0), $this->toFloat($data['y'] ?? 0), $this->toFloat($data['z'] ?? 0)),
                Rect::class => new Rect($this->toFloat($data['x'] ?? 0), $this->toFloat($data['y'] ?? 0), $this->toFloat($data['width'] ?? 0), $this->toFloat($data['height'] ?? 0)),
                Color::class => new Color($this->toFloat($data['r'] ?? 0), $this->toFloat($data['g'] ?? 0), $this->toFloat($data['b'] ?? 0), $this->toFloat($data['a'] ?? 1)),
                'array' => $data,
                default => $this->tryDeserializeObject($data, $typeName),
            };
        }

        if (is_string($data) || is_int($data)) {
            if (enum_exists($typeName)) {
                /** @var class-string<\BackedEnum> $typeName */
                return $typeName::from($data);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tryDeserializeObject(array $data, string $typeName): mixed
    {
        if (!class_exists($typeName)) {
            return $data;
        }

        $class = new ReflectionClass($typeName);
        if (!empty($class->getAttributes(Serializable::class))) {
            $candidate = isset($data['_class']) && is_string($data['_class']) ? $data['_class'] : $typeName;
            if (!class_exists($candidate)) {
                return $data;
            }
            return $this->fromArray($data, $candidate);
        }

        return $data;
    }

    private function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return 0.0;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<array{property: ReflectionProperty, attribute: Property}>
     */
    private function getSerializableProperties(ReflectionClass $class): array
    {
        $className = $class->getName();
        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        $result = [];
        foreach ($class->getProperties() as $prop) {
            if (!empty($prop->getAttributes(Hidden::class))) {
                continue;
            }

            $attrs = $prop->getAttributes(Property::class);
            if (empty($attrs)) {
                continue;
            }

            $result[] = [
                'property' => $prop,
                'attribute' => $attrs[0]->newInstance(),
            ];
        }

        $this->cache[$className] = $result;
        return $result;
    }

    /** @param ReflectionClass<object> $class */
    private function assertSerializable(ReflectionClass $class): void
    {
        if (empty($class->getAttributes(Serializable::class))) {
            throw new RuntimeException(
                "Class {$class->getName()} is not marked with #[Serializable]"
            );
        }
    }
}
