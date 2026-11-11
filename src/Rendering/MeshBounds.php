<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;

/**
 * Local- and world-space bounding sphere of a registered mesh, shared by
 * {@see \PHPolygon\System\Renderer3DSystem} (per-entity frustum/LOD test,
 * transparent sort pivot) and {@see \PHPolygon\Visibility\VisibilityService}
 * (SpatialIndex entries). Extracted from Renderer3DSystem's former private
 * getMeshSphere()/$sphereCache so both have exactly one place computing it.
 *
 * Like the code it replaces, the cache is keyed by mesh id only and is never
 * invalidated by {@see MeshRegistry::clear()} - re-registering a DIFFERENT
 * mesh under an id already cached here keeps returning the old sphere. This
 * is a pre-existing limitation (not introduced here); nothing in the engine
 * currently re-registers a mesh id with different geometry outside tests
 * that start a fresh PHP process per suite run.
 */
final class MeshBounds
{
    private function __construct()
    {
    }

    /** @var array<string, array{cx:float, cy:float, cz:float, radius:float}> */
    private static array $sphereCache = [];

    /**
     * Local-space bounding sphere of a mesh's raw vertex array, or null if
     * the mesh isn't registered yet.
     *
     * @return array{cx:float, cy:float, cz:float, radius:float}|null
     */
    public static function localSphere(string $meshId): ?array
    {
        if (isset(self::$sphereCache[$meshId])) {
            return self::$sphereCache[$meshId];
        }

        if (!MeshRegistry::has($meshId)) {
            return null;
        }
        $mesh = MeshRegistry::get($meshId);
        if ($mesh === null) {
            return null;
        }

        $verts = $mesh->vertices;
        $count = count($verts);
        if ($count < 3) {
            return self::$sphereCache[$meshId] = ['cx' => 0.0, 'cy' => 0.0, 'cz' => 0.0, 'radius' => 0.0];
        }

        // First pass: AABB.
        $minX = $maxX = $verts[0];
        $minY = $maxY = $verts[1];
        $minZ = $maxZ = $verts[2];
        for ($i = 3; $i < $count; $i += 3) {
            $x = $verts[$i];
            $y = $verts[$i + 1];
            $z = $verts[$i + 2];
            if ($x < $minX) { $minX = $x; } elseif ($x > $maxX) { $maxX = $x; }
            if ($y < $minY) { $minY = $y; } elseif ($y > $maxY) { $maxY = $y; }
            if ($z < $minZ) { $minZ = $z; } elseif ($z > $maxZ) { $maxZ = $z; }
        }

        $cx = ($minX + $maxX) * 0.5;
        $cy = ($minY + $maxY) * 0.5;
        $cz = ($minZ + $maxZ) * 0.5;

        // Second pass: tightest sphere around the AABB centre.
        $rSq = 0.0;
        for ($i = 0; $i < $count; $i += 3) {
            $dx = $verts[$i]     - $cx;
            $dy = $verts[$i + 1] - $cy;
            $dz = $verts[$i + 2] - $cz;
            $d = $dx * $dx + $dy * $dy + $dz * $dz;
            if ($d > $rSq) {
                $rSq = $d;
            }
        }

        return self::$sphereCache[$meshId] = [
            'cx' => $cx,
            'cy' => $cy,
            'cz' => $cz,
            'radius' => sqrt($rSq),
        ];
    }

    /**
     * World-space bounding sphere: the local sphere's centre transformed by
     * $matrix, radius scaled by the matrix's largest axis scale (so any
     * rotation of a non-uniformly scaled mesh still lies inside the sphere).
     * Null when the mesh isn't registered yet - the caller must then treat
     * the entity as unbounded (never culled) rather than invisible.
     *
     * @return array{cx:float, cy:float, cz:float, radius:float}|null
     */
    public static function worldSphere(string $meshId, Mat4 $matrix): ?array
    {
        $local = self::localSphere($meshId);
        if ($local === null) {
            return null;
        }

        if ($local['cx'] === 0.0 && $local['cy'] === 0.0 && $local['cz'] === 0.0) {
            // Most procedural meshes (Box/Sphere/Cylinder) are centred at the
            // local origin - the world centre is just the matrix translation,
            // skipping a Mat4::transformPoint() allocation.
            $tr = $matrix->getTranslation();
            $cx = $tr->x;
            $cy = $tr->y;
            $cz = $tr->z;
        } else {
            $world = $matrix->transformPoint(new \PHPolygon\Math\Vec3($local['cx'], $local['cy'], $local['cz']));
            $cx = $world->x;
            $cy = $world->y;
            $cz = $world->z;
        }

        return [
            'cx' => $cx,
            'cy' => $cy,
            'cz' => $cz,
            'radius' => $local['radius'] * self::maxAxisScale($matrix),
        ];
    }

    /**
     * Largest axis scale of a world matrix (column lengths of its 3x3 part),
     * so a bounding sphere scaled by it contains the mesh under any rotation
     * and any parent scale. Mirrors Renderer3DSystem's own copy (kept there
     * too - an 8-line pure function, not worth threading a dependency for).
     */
    public static function maxAxisScale(Mat4 $matrix): float
    {
        $m = $matrix->toArray();
        $sx = $m[0] * $m[0] + $m[1] * $m[1] + $m[2] * $m[2];
        $sy = $m[4] * $m[4] + $m[5] * $m[5] + $m[6] * $m[6];
        $sz = $m[8] * $m[8] + $m[9] * $m[9] + $m[10] * $m[10];
        return sqrt($sx > $sy ? ($sx > $sz ? $sx : $sz) : ($sy > $sz ? $sy : $sz));
    }
}
