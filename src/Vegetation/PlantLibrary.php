<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * Static registry of the plant variants a game registers at boot - the
 * vegetation counterpart of MeshRegistry / MaterialRegistry. Components refer
 * to a variant by its id string, so scenes stay serialisable.
 */
final class PlantLibrary
{
    /** @var array<string, PlantVariant> */
    private static array $variants = [];

    /** Register a variant and its (lazily generated) meshes. Re-registering an id replaces it. */
    public static function register(PlantVariant $variant): PlantVariant
    {
        self::$variants[$variant->id] = $variant;
        $variant->registerMeshes();
        return $variant;
    }

    public static function get(string $id): ?PlantVariant
    {
        return self::$variants[$id] ?? null;
    }

    public static function has(string $id): bool
    {
        return isset(self::$variants[$id]);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::$variants);
    }

    public static function clear(): void
    {
        self::$variants = [];
    }
}
