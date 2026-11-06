<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Reflection;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\ProcModeShaderRegistry;

/**
 * Planar reflection for one horizontal mirror plane (a sea, a pool): while enabled,
 * the renderer draws the opaque scene a second time, mirrored at y = plane, into a
 * target of `scale` times the scene size, before the main pass. The mirror camera
 * keeps the projection, with its near plane bent onto the mirror (oblique clipping),
 * so nothing below the plane shows up and no shader needs a clip test.
 *
 * GLSL (library, programs passed to {@see register()}):
 *   vec3 planarReflectionSample(vec3 N, float distortion, vec3 fallback)
 * returns the mirrored scene behind the fragment, its lookup pushed by the surface
 * normal's xz times `distortion` (waves), and `fallback` where the mirror holds no
 * geometry (sky, beyond the far plane) or no reflection was drawn this frame. One
 * sampler of each listed program's budget.
 *
 * Expensive (a second opaque pass): a game enables it by its graphics tier. Vio
 * family only.
 */
final class PlanarReflection
{
    public const string SAMPLER = 'u_planar_reflection';

    /** x = plane height, y = 1 when this frame's reflection is bound, zw unused. */
    public const string INFO = 'u_planar_info';

    private const string LIBRARY = 'phpolygon_planar_reflection';

    private static bool $registered = false;

    private static ?float $plane = null;

    private static float $scale = 0.5;

    /** @param list<string>|null $programs programs that get the library and the sampler */
    public static function register(?array $programs = null): void
    {
        ProcModeShaderRegistry::declareSampler(self::SAMPLER, $programs);
        ProcModeShaderRegistry::declareUniform(self::INFO, 'vec4');
        $path = dirname(__DIR__, 3) . '/resources/shaders/source/vio/reflection/planar_reflection.glsl';
        $glsl = @file_get_contents($path);
        if ($glsl === false) {
            throw new \RuntimeException("Planar reflection library missing: {$path}");
        }
        ProcModeShaderRegistry::registerLibrary(self::LIBRARY, $glsl, null, $programs);
        self::$registered = true;
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /** Draw the reflection at y = $plane from now on, at $scale of the scene size (0.25..1). */
    public static function enable(float $plane, float $scale = 0.5): void
    {
        self::$plane = $plane;
        self::$scale = max(0.25, min(1.0, $scale));
    }

    public static function disable(): void
    {
        self::$plane = null;
    }

    /** True when registered and enabled: the renderer draws the pass. */
    public static function active(): bool
    {
        return self::$registered && self::$plane !== null;
    }

    public static function plane(): ?float
    {
        return self::$plane;
    }

    public static function scale(): float
    {
        return self::$scale;
    }

    public static function clear(): void
    {
        self::$registered = false;
        self::$plane = null;
        self::$scale = 0.5;
    }

    /** The view matrix mirrored at y = $plane: view · (y → 2·plane − y). */
    public static function mirroredView(Mat4 $view, float $plane): Mat4
    {
        $mirror = new Mat4([
            1.0, 0.0, 0.0, 0.0,
            0.0, -1.0, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            0.0, 2.0 * $plane, 0.0, 1.0,
        ]);

        return $view->multiply($mirror);
    }

    /** Where the mirrored camera sits. */
    public static function mirroredEye(Vec3 $eye, float $plane): Vec3
    {
        return new Vec3($eye->x, 2.0 * $plane - $eye->y, $eye->z);
    }

    /**
     * $projection with its near plane replaced by the mirror plane (Lengyel, "Oblique
     * View Frustum Depth Projection and Clipping"), GL clip convention: everything on
     * the far side of y = $plane, seen through $mirroredView, falls behind the near
     * plane. A plane that does not face the camera leaves the projection unchanged.
     */
    public static function obliqueProjection(Mat4 $projection, Mat4 $mirroredView, float $plane): Mat4
    {
        // The plane y = plane, keeping y > plane, in view space: n_v = inverse(V)^T · n.
        $inv = $mirroredView->inverse()->toArray();
        $n = [0.0, 1.0, 0.0, -$plane];
        $c = [];
        for ($row = 0; $row < 4; $row++) {
            // (inverse(V)^T)[row][k] = inverse(V)[k][row]; column-major: m[col * 4 + row].
            $c[$row] = $inv[$row * 4 + 0] * $n[0] + $inv[$row * 4 + 1] * $n[1] + $inv[$row * 4 + 2] * $n[2] + $inv[$row * 4 + 3] * $n[3];
        }
        if ($c[3] >= 0.0) {
            return $projection; // camera on the wrong side or in the plane
        }
        $m = $projection->toArray();
        $sx = $c[0] > 0.0 ? 1.0 : ($c[0] < 0.0 ? -1.0 : 0.0);
        $sy = $c[1] > 0.0 ? 1.0 : ($c[1] < 0.0 ? -1.0 : 0.0);
        // Corner of the frustum opposite the plane, in view space: q = P^-1 · (sx, sy, 1, 1).
        $q = [
            ($sx + $m[8]) / $m[0],
            ($sy + $m[9]) / $m[5],
            -1.0,
            (1.0 + $m[10]) / $m[14],
        ];
        $dot = $c[0] * $q[0] + $c[1] * $q[1] + $c[2] * $q[2] + $c[3] * $q[3];
        if (abs($dot) < 1e-12) {
            return $projection;
        }
        $k = 2.0 / $dot;
        // Third row = c·k − fourth row.
        $m[2] = $c[0] * $k - $m[3];
        $m[6] = $c[1] * $k - $m[7];
        $m[10] = $c[2] * $k - $m[11];
        $m[14] = $c[3] * $k - $m[15];

        return new Mat4($m);
    }
}
