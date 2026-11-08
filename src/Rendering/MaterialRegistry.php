<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

class MaterialRegistry
{
    /** @var array<string, Material> */
    private static array $registry = [];

    /** Bumped on every change, so a renderer re-reads the registry only when it changed. */
    private static int $version = 0;

    /** @var array<string, int> material id → version it last changed at */
    private static array $stamps = [];

    public static function register(string $id, Material $material): void
    {
        // The same object again (systems that re-register every frame) is no change.
        if ((self::$registry[$id] ?? null) === $material) {
            return;
        }
        self::$registry[$id] = $material;
        self::$stamps[$id] = ++self::$version;
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
        self::$stamps = [];
        self::$version++;
    }

    /** @return string[] */
    public static function ids(): array
    {
        return array_keys(self::$registry);
    }

    /** @return array<string, Material> */
    public static function all(): array
    {
        return self::$registry;
    }

    /** Changes with every register() of a new material object and every clear(). */
    public static function version(): int
    {
        return self::$version;
    }

    /**
     * The materials registered or replaced after $version (a value {@see version()}
     * returned earlier).
     *
     * @return array<string, Material>
     */
    public static function changedSince(int $version): array
    {
        $out = [];
        foreach (self::$stamps as $id => $stamp) {
            if ($stamp > $version) {
                $out[$id] = self::$registry[$id];
            }
        }
        return $out;
    }
}
