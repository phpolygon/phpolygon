<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Registry for cubemap face paths. The renderer uploads them to the GPU.
 * Face order: +X, -X, +Y, -Y, +Z, -Z (standard OpenGL cubemap order).
 */
class CubemapRegistry
{
    /** @var array<string, CubemapFaces> */
    private static array $registry = [];

    public static function register(string $id, CubemapFaces $faces): void
    {
        self::$registry[$id] = $faces;
    }

    public static function get(string $id): ?CubemapFaces
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
}
