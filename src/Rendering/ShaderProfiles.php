<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Shader profiles: lean programs for groups of elements. A profile names the
 * {@see ShaderPath::automatic()} paths its elements may use; its program is compiled
 * without every other one. A building then pays nothing for hair shine or
 * refraction, a figure nothing for splatted ground.
 *
 * Which profile a material draws with:
 *  1. Material::$shaderProfile, when set;
 *  2. else the first registered profile with an id prefix the material id starts with;
 *  3. else the registered profile with the fewest paths that covers the material.
 * A profile never takes a path from a material that needs it: such an assignment is
 * a violation ({@see violations()}) and the material draws with the base program.
 * Without registered profiles nothing changes.
 *
 * Games register their profiles before the renderer builds its programs (the
 * profile programs are compiled up front, like the game's shader variants).
 */
final class ShaderProfiles
{
    /** @var array<string, array{paths: list<ShaderPath>, prefixes: list<string>}> */
    private static array $profiles = [];

    /** Bumped on every change: a renderer rebuilds its profile programs then. */
    private static int $version = 0;

    /** @var array<string, array{0: Material, 1: ?string}> material id → (material, resolved profile) */
    private static array $resolved = [];

    /**
     * @param list<ShaderPath> $paths      the automatic paths the profile keeps
     * @param list<string>     $idPrefixes material ids starting with one of these use the profile
     */
    public static function register(string $name, array $paths, array $idPrefixes = []): void
    {
        if ($name === '' || str_contains($name, '+') || str_contains($name, '@')) {
            throw new \InvalidArgumentException("ShaderProfiles: invalid profile name '{$name}'.");
        }
        $unique = [];
        foreach ($paths as $path) {
            $unique[$path->value] = $path;
        }
        self::$profiles[$name] = ['paths' => array_values($unique), 'prefixes' => $idPrefixes];
        self::$resolved = [];
        self::$version++;
    }

    public static function clear(): void
    {
        self::$profiles = [];
        self::$resolved = [];
        self::$version++;
    }

    public static function version(): int
    {
        return self::$version;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::$profiles);
    }

    /**
     * The OMIT defines of a profile's program: every automatic path it does not keep.
     *
     * @return list<string>
     */
    public static function omissions(string $name): array
    {
        $keep = self::$profiles[$name]['paths'] ?? null;
        if ($keep === null) {
            return [];
        }
        $out = [];
        foreach (ShaderPath::automatic() as $path) {
            if (!in_array($path, $keep, true)) {
                $out[] = $path->define();
            }
        }
        return $out;
    }

    /** The profile $materialId draws with, null for the base program. */
    public static function profileOf(string $materialId, Material $material): ?string
    {
        if (self::$profiles === []) {
            return null;
        }
        $hit = self::$resolved[$materialId] ?? null;
        if ($hit !== null && $hit[0] === $material) {
            return $hit[1];
        }
        $name = self::assigned($materialId, $material);
        if ($name !== null && !self::covers($name, $material)) {
            $name = null; // a violation: the base program keeps every path
        }
        self::$resolved[$materialId] = [$material, $name];
        return $name;
    }

    /**
     * Assignments that would take a needed path away (explicit or by prefix), as
     * "material id: profile lacks PATH, …" - empty when every element fits its profile.
     *
     * @param array<string, Material>|null $materials defaults to every registered material
     * @return list<string>
     */
    public static function violations(?array $materials = null): array
    {
        $out = [];
        foreach ($materials ?? MaterialRegistry::all() as $id => $material) {
            $name = self::assigned((string) $id, $material);
            if ($name === null) {
                continue;
            }
            $missing = self::missing($name, $material);
            if ($missing !== []) {
                $out[] = $id . ': ' . $name . ' lacks ' . implode(', ', array_map(static fn (ShaderPath $p): string => $p->value, $missing));
            }
        }
        return $out;
    }

    /** The profile a material is assigned to, before the coverage check. */
    private static function assigned(string $materialId, Material $material): ?string
    {
        if ($material->shaderProfile !== null) {
            return isset(self::$profiles[$material->shaderProfile]) ? $material->shaderProfile : null;
        }
        foreach (self::$profiles as $name => $profile) {
            foreach ($profile['prefixes'] as $prefix) {
                if ($prefix !== '' && str_starts_with($materialId, $prefix)) {
                    return $name;
                }
            }
        }
        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach (self::$profiles as $name => $profile) {
            if (count($profile['paths']) < $bestCount && self::covers($name, $material)) {
                $best = $name;
                $bestCount = count($profile['paths']);
            }
        }
        return $best;
    }

    private static function covers(string $name, Material $material): bool
    {
        return self::missing($name, $material) === [];
    }

    /** @return list<ShaderPath> */
    private static function missing(string $name, Material $material): array
    {
        $keep = self::$profiles[$name]['paths'] ?? [];
        $out = [];
        foreach (ShaderPath::automatic() as $path) {
            if (!in_array($path, $keep, true) && $path->neededBy($material)) {
                $out[] = $path;
            }
        }
        return $out;
    }
}
