<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

class MaterialRegistry
{
    /** @var array<string, Material> */
    private static array $registry = [];

    public static function register(string $id, Material $material): void
    {
        self::$registry[$id] = $material;
    }

    public static function get(string $id): ?Material
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
