<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Global registry mapping shader IDs to ShaderDefinition instances.
 * Games register custom shaders here; backends compile on first use.
 *
 * The 'default' shader is registered automatically by the engine and
 * maps to the built-in mesh3d PBR shader.
 */
class ShaderRegistry
{
    /** @var array<string, ShaderDefinition> */
    private static array $registry = [];

    public static function register(string $id, ShaderDefinition $definition): void
    {
        self::$registry[$id] = $definition;
    }

    public static function get(string $id): ?ShaderDefinition
    {
        return self::$registry[$id] ?? null;
    }

    public static function has(string $id): bool
    {
        return isset(self::$registry[$id]);
    }

    public static function clear(): void
    {
        self::$registry = [];
    }

    /** @return string[] */
    public static function ids(): array
    {
        return array_keys(self::$registry);
    }
}
