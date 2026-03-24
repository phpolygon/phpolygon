<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use RuntimeException;

class JsonSceneFormat
{
    public const VERSION = 1;

    /**
     * Validate a parsed JSON scene structure.
     *
     * @param array<string, mixed> $data
     * @throws RuntimeException
     */
    public static function validate(array $data): void
    {
        $version = $data['_version'] ?? null;
        if ($version !== null && $version > self::VERSION) {
            $versionStr = is_int($version) ? $version : 0;
            throw new RuntimeException(
                "Scene format version {$versionStr} is not supported (max: " . self::VERSION . ")"
            );
        }

        if (!isset($data['name']) || !is_string($data['name'])) {
            throw new RuntimeException("Scene JSON must have a 'name' string field");
        }

        if (!isset($data['entities']) || !is_array($data['entities'])) {
            throw new RuntimeException("Scene JSON must have an 'entities' array field");
        }

        foreach ($data['entities'] as $i => $entity) {
            if (!is_array($entity)) {
                throw new RuntimeException("Entity at entities[{$i}] must be an array");
            }
            /** @var array<string, mixed> $entity */
            self::validateEntity($entity, "entities[{$i}]");
        }
    }

    /**
     * @param array<string, mixed> $entity
     */
    private static function validateEntity(array $entity, string $path): void
    {
        if (!isset($entity['name']) || !is_string($entity['name'])) {
            throw new RuntimeException("Entity at {$path} must have a 'name' string field");
        }

        if (!isset($entity['components']) || !is_array($entity['components'])) {
            throw new RuntimeException("Entity at {$path} must have a 'components' array field");
        }

        foreach ($entity['components'] as $j => $component) {
            if (!is_array($component) || !isset($component['_class']) || !is_string($component['_class'])) {
                throw new RuntimeException(
                    "Component at {$path}.components[{$j}] must have a '_class' string field"
                );
            }
        }

        if (isset($entity['children']) && is_array($entity['children'])) {
            foreach ($entity['children'] as $j => $child) {
                if (!is_array($child)) {
                    throw new RuntimeException("Child at {$path}.children[{$j}] must be an array");
                }
                /** @var array<string, mixed> $child */
                self::validateEntity($child, "{$path}.children[{$j}]");
            }
        }
    }
}
