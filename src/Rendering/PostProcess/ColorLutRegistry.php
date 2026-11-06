<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * Colour lookup tables by id, for {@see ImageLook::$lut} / `$lutBlend`. A renderer uploads
 * a table the first time a look names it and again when it is registered anew.
 */
final class ColorLutRegistry
{
    /** @var array<string, ColorLut> */
    private static array $luts = [];

    /** @var array<string, int> */
    private static array $versions = [];

    private static int $counter = 0;

    public static function register(string $id, ColorLut $lut): void
    {
        self::$luts[$id] = $lut;
        self::$versions[$id] = ++self::$counter;
    }

    public static function get(string $id): ?ColorLut
    {
        return self::$luts[$id] ?? null;
    }

    /** Changes whenever the table of $id is replaced; 0 for an unknown id. */
    public static function version(string $id): int
    {
        return self::$versions[$id] ?? 0;
    }

    public static function clear(): void
    {
        self::$luts = [];
        self::$versions = [];
    }
}
