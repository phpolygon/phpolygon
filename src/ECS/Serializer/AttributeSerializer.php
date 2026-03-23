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
                'int' => (int)$data,
                'float' => (float)$data,
                'string' => (string)$data,
                'bool' => (bool)$data,
                default => $data,
            };
        }

        if (is_array($data)) {
            return match ($typeName) {
                Vec2::class => Vec2::fromArray($data),
                Vec3::class => Vec3::fromArray($data),
                Rect::class => Rect::fromArray($data),
                Color::class => Color::fromArray($data),
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
            $actualClass = $data['_class'] ?? $typeName;
            return $this->fromArray($data, $actualClass);
        }

        return $data;
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
