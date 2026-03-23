<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Inspector;

use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

class InspectorMetadataExtractor
{
    /** @var array<class-string, ComponentSchema> */
    private array $cache = [];

    /**
     * @param class-string $className
     */
    public function extract(string $className): ComponentSchema
    {
        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        $ref = new ReflectionClass($className);

        if (empty($ref->getAttributes(Serializable::class))) {
            throw new RuntimeException("Class {$className} is not marked with #[Serializable]");
        }

        // Extract category
        $categoryAttrs = $ref->getAttributes(Category::class);
        $category = !empty($categoryAttrs) ? $categoryAttrs[0]->newInstance()->name : null;

        // Create a default instance to read property defaults
        $defaultInstance = $ref->newInstanceWithoutConstructor();
        $constructor = $ref->getConstructor();
        if ($constructor !== null) {
            // Try to instantiate with defaults
            try {
                $defaultInstance = $ref->newInstance();
            } catch (\Throwable) {
                // Fall back to without constructor
            }
        }

        // Extract properties
        $properties = [];
        foreach ($ref->getProperties() as $prop) {
            if (!empty($prop->getAttributes(Hidden::class))) {
                continue;
            }

            $propertyAttrs = $prop->getAttributes(Property::class);
            if (empty($propertyAttrs)) {
                continue;
            }

            /** @var Property $propertyAttr */
            $propertyAttr = $propertyAttrs[0]->newInstance();

            // Type
            $type = $prop->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
            $nullable = $type !== null && $type->allowsNull();

            // Range
            $rangeAttrs = $prop->getAttributes(Range::class);
            $range = null;
            if (!empty($rangeAttrs)) {
                /** @var Range $rangeAttr */
                $rangeAttr = $rangeAttrs[0]->newInstance();
                $range = ['min' => $rangeAttr->min, 'max' => $rangeAttr->max];
            }

            // Default value
            $default = null;
            if ($prop->isInitialized($defaultInstance)) {
                $rawDefault = $prop->getValue($defaultInstance);
                $default = $this->serializeDefault($rawDefault);
            }

            $properties[] = new PropertySchema(
                name: $prop->getName(),
                displayName: $propertyAttr->name ?? $prop->getName(),
                type: $typeName,
                nullable: $nullable,
                default: $default,
                editorHint: $propertyAttr->editorHint,
                description: $propertyAttr->description,
                range: $range,
            );
        }

        $shortName = $ref->getShortName();
        $schema = new ComponentSchema($className, $shortName, $category, $properties);
        $this->cache[$className] = $schema;
        return $schema;
    }

    private function serializeDefault(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return null;
    }

}
