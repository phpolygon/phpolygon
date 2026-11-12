<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Reflection;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Reflection through an arbitrary plane: the matrices of a mirrored camera.
 * {@see PlanarReflection} does the same for a horizontal plane (water); this is the
 * general form for a wall, a door, a ceiling. Column-major like {@see Mat4}.
 */
final class MirrorPlane
{
    private function __construct() {}

    /** Reflection of the world through the plane (point, unit normal): x -> x - 2 (n.x - d) n. */
    public static function reflection(Vec3 $point, Vec3 $normal): Mat4
    {
        $n = [$normal->x, $normal->y, $normal->z];
        $d = $normal->dot($point);
        $m = [];
        for ($col = 0; $col < 4; $col++) {
            for ($row = 0; $row < 4; $row++) {
                if ($col < 3 && $row < 3) {
                    $m[$col * 4 + $row] = ($col === $row ? 1.0 : 0.0) - 2.0 * $n[$row] * $n[$col];
                } elseif ($col === 3 && $row < 3) {
                    $m[$col * 4 + $row] = 2.0 * $d * $n[$row];
                } else {
                    $m[$col * 4 + $row] = $row === 3 && $col === 3 ? 1.0 : 0.0;
                }
            }
        }

        return new Mat4($m);
    }

    /** The view matrix of the mirrored camera: view . reflection. */
    public static function mirroredView(Mat4 $view, Vec3 $point, Vec3 $normal): Mat4
    {
        return $view->multiply(self::reflection($point, $normal));
    }

    /** Where the mirrored camera sits. */
    public static function mirroredEye(Vec3 $eye, Vec3 $point, Vec3 $normal): Vec3
    {
        return $eye->sub($normal->mul(2.0 * ($normal->dot($eye) - $normal->dot($point))));
    }

    /**
     * $projection with its near plane replaced by the mirror plane (Lengyel, "Oblique View
     * Frustum Depth Projection and Clipping"), GL clip convention. $mirroredView is the
     * view of {@see mirroredView()}; the plane is the world plane (point, normal) moved
     * $bias metres toward the viewers. What lies behind it in the world (n.x < d + bias)
     * falls behind the near plane of the image. A projection the plane does not face
     * (eye on the wrong side, or a plane through the eye) comes back unchanged.
     */
    public static function obliqueProjection(Mat4 $projection, Mat4 $mirroredView, Vec3 $point, Vec3 $normal, float $bias = 0.0): Mat4
    {
        $plane = [$normal->x, $normal->y, $normal->z, -($normal->dot($point) + $bias)];
        // The plane in view space: inverse(V)^T . plane.
        $inv = $mirroredView->inverse()->toArray();
        $c = [];
        for ($row = 0; $row < 4; $row++) {
            $c[$row] = $inv[$row * 4 + 0] * $plane[0] + $inv[$row * 4 + 1] * $plane[1] + $inv[$row * 4 + 2] * $plane[2] + $inv[$row * 4 + 3] * $plane[3];
        }
        if ($c[3] >= 0.0) {
            return $projection;
        }
        $m = $projection->toArray();
        $sx = $c[0] > 0.0 ? 1.0 : ($c[0] < 0.0 ? -1.0 : 0.0);
        $sy = $c[1] > 0.0 ? 1.0 : ($c[1] < 0.0 ? -1.0 : 0.0);
        // Corner of the frustum opposite the plane, in view space: q = P^-1 . (sx, sy, 1, 1).
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
        $m[2] = $c[0] * $k - $m[3];
        $m[6] = $c[1] * $k - $m[7];
        $m[10] = $c[2] * $k - $m[11];
        $m[14] = $c[3] * $k - $m[15];

        return new Mat4($m);
    }
}
