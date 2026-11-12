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

    /** @var array<string, array{0: float, 1: float, 2: float, 3: float}> culling spheres: centre xyz, radius */
    private static array $spheres = [];

    /**
     * @param ?array{min: array{0: float, 1: float, 2: float}, max: array{0: float, 1: float, 2: float}} $bounds
     *        Local-space box that holds every pose the mesh is drawn in, e.g. {@see SkinnedMeshData::boundsOver()}
     *        over the clips' palettes. Without it the renderer culls with the bind-pose box grown by 50 %
     *        around its centre (a guess: arms swung out of the bind pose may leave it).
     */
    public static function register(string $id, SkinnedMeshData $mesh, ?array $bounds = null): void
    {
        self::$meshes[$id] = $mesh;
        self::$versions[$id] = (self::$versions[$id] ?? 0) + 1;
        $grow = 1.0;
        if ($bounds === null) {
            $b = $mesh->mesh->bounds();
            $bounds = ['min' => [$b['min']->x, $b['min']->y, $b['min']->z], 'max' => [$b['max']->x, $b['max']->y, $b['max']->z]];
            $grow = 1.5;
        }
        $cx = ($bounds['min'][0] + $bounds['max'][0]) / 2.0;
        $cy = ($bounds['min'][1] + $bounds['max'][1]) / 2.0;
        $cz = ($bounds['min'][2] + $bounds['max'][2]) / 2.0;
        $r = 0.5 * sqrt(($bounds['max'][0] - $bounds['min'][0]) ** 2 + ($bounds['max'][1] - $bounds['min'][1]) ** 2 + ($bounds['max'][2] - $bounds['min'][2]) ** 2);
        self::$spheres[$id] = [$cx, $cy, $cz, $r * $grow];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null centre xyz and radius, local space */
    public static function sphere(string $id): ?array
    {
        return self::$spheres[$id] ?? null;
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
        self::$spheres = [];
    }
}
