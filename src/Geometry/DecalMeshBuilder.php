<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Mat4;

/**
 * Mesh decals (shader plan 5.1): where the volume decals of the mesh shader stop -
 * more than their eight slots, or a texture (a sign painted on the floor, a marking,
 * a poster on a curved wall) - the decal becomes geometry of its own: the triangles of
 * the target mesh inside the decal box, clipped to it, lifted a little off the surface
 * and given UVs projected over the box. Draw the result with a textured, alpha-blended
 * or alpha-cut material.
 *
 * The decal box is the cube [-1, 1]^3 mapped into the world by $decal (translation =
 * centre, rotation, scale = half extents). It projects along its local -Y onto faces
 * turned towards +Y within $maxAngleDeg; u runs along local +X, the image's top lies
 * towards local -Z.
 */
final class DecalMeshBuilder
{
    public static function build(MeshData $target, Mat4 $targetModel, Mat4 $decal, float $maxAngleDeg = 80.0, float $offset = 0.005): MeshData
    {
        $world = $target->transformed($targetModel);
        $inv = $decal->inverse()->toArray();
        $d = $decal->toArray();
        $up = self::normalize([$d[4], $d[5], $d[6]]);
        $minCos = cos(deg2rad($maxAngleDeg));

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        $v = $world->vertices;
        $n = $world->normals;
        for ($t = 0, $count = count($world->indices); $t + 2 < $count; $t += 3) {
            $poly = [];
            foreach ([$world->indices[$t], $world->indices[$t + 1], $world->indices[$t + 2]] as $i) {
                $p = [(float) $v[$i * 3], (float) $v[$i * 3 + 1], (float) $v[$i * 3 + 2]];
                $poly[] = [
                    'p' => $p,
                    'n' => [(float) ($n[$i * 3] ?? 0.0), (float) ($n[$i * 3 + 1] ?? 1.0), (float) ($n[$i * 3 + 2] ?? 0.0)],
                    'l' => [
                        $inv[0] * $p[0] + $inv[4] * $p[1] + $inv[8] * $p[2] + $inv[12],
                        $inv[1] * $p[0] + $inv[5] * $p[1] + $inv[9] * $p[2] + $inv[13],
                        $inv[2] * $p[0] + $inv[6] * $p[1] + $inv[10] * $p[2] + $inv[14],
                    ],
                ];
            }
            $face = self::normalize(self::cross(self::sub($poly[1]['p'], $poly[0]['p']), self::sub($poly[2]['p'], $poly[0]['p'])));
            if ($face[0] * $up[0] + $face[1] * $up[1] + $face[2] * $up[2] < $minCos) {
                continue;
            }
            foreach ([0, 1, 2] as $axis) {
                foreach ([1.0, -1.0] as $sign) {
                    $poly = self::clip($poly, $axis, $sign);
                    if (count($poly) < 3) {
                        continue 3;
                    }
                }
            }
            $base = intdiv(count($vertices), 3);
            foreach ($poly as $q) {
                array_push($vertices, $q['p'][0] + $face[0] * $offset, $q['p'][1] + $face[1] * $offset, $q['p'][2] + $face[2] * $offset);
                $qn = self::normalize($q['n']);
                array_push($normals, $qn[0], $qn[1], $qn[2]);
                array_push($uvs, $q['l'][0] * 0.5 + 0.5, $q['l'][2] * 0.5 + 0.5);
            }
            for ($k = 1, $m = count($poly) - 1; $k < $m; $k++) {
                array_push($indices, $base, $base + $k, $base + $k + 1);
            }
        }
        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    /**
     * Sutherland-Hodgman against the plane sign * l[axis] = 1, keeping sign * l[axis] <= 1.
     *
     * @param list<array{p: list<float>, n: list<float>, l: list<float>}> $poly
     * @return list<array{p: list<float>, n: list<float>, l: list<float>}>
     */
    private static function clip(array $poly, int $axis, float $sign): array
    {
        $out = [];
        $count = count($poly);
        for ($i = 0; $i < $count; $i++) {
            $a = $poly[$i];
            $b = $poly[($i + 1) % $count];
            $da = $sign * $a['l'][$axis] - 1.0;
            $db = $sign * $b['l'][$axis] - 1.0;
            if ($da <= 0.0) {
                $out[] = $a;
            }
            if (($da <= 0.0) !== ($db <= 0.0)) {
                $t = $da / ($da - $db);
                $out[] = [
                    'p' => self::lerp($a['p'], $b['p'], $t),
                    'n' => self::lerp($a['n'], $b['n'], $t),
                    'l' => self::lerp($a['l'], $b['l'], $t),
                ];
            }
        }
        return $out;
    }

    /** @param list<float> $a @param list<float> $b @return list<float> */
    private static function lerp(array $a, array $b, float $t): array
    {
        return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
    }

    /** @param list<float> $a @param list<float> $b @return list<float> */
    private static function sub(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
    }

    /** @param list<float> $a @param list<float> $b @return list<float> */
    private static function cross(array $a, array $b): array
    {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }

    /** @param list<float> $a @return list<float> */
    private static function normalize(array $a): array
    {
        $l = sqrt($a[0] * $a[0] + $a[1] * $a[1] + $a[2] * $a[2]);
        return $l > 1e-12 ? [$a[0] / $l, $a[1] / $l, $a[2] / $l] : [0.0, 1.0, 0.0];
    }
}
