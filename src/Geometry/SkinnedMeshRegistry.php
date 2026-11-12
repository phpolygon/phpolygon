<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Skinned meshes by id, the counterpart of {@see MeshRegistry} for
 * {@see \PHPolygon\Rendering\Command\DrawSkinnedMesh}: the renderer uploads the vertex
 * data (positions, normals, UVs, joint indices and weights) once per version and
 * poses it on the GPU from a bone palette, so nothing is re-registered per frame.
 */
final class SkinnedMeshRegistry
{
    /** @var array<string, SkinnedMeshData> */
    private static array $meshes = [];
    /** @var array<string, int> */
    private static array $versions = [];

    public static function register(string $id, SkinnedMeshData $mesh): void
    {
        self::$meshes[$id] = $mesh;
        self::$versions[$id] = (self::$versions[$id] ?? 0) + 1;
    }

    public static function get(string $id): ?SkinnedMeshData
    {
        return self::$meshes[$id] ?? null;
    }

    public static function has(string $id): bool
    {
        return isset(self::$meshes[$id]);
    }

    /** Bumped by every register(); the renderer re-uploads on a change. */
    public static function version(string $id): int
    {
        return self::$versions[$id] ?? 0;
    }

    public static function clear(): void
    {
        self::$meshes = [];
        self::$versions = [];
    }
}
