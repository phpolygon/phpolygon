<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec3;

/**
 * Box and plane generators with metric UVs: one UV unit is one world metre
 * (times $uvScale) on every face, so a surface pattern keeps its scale no
 * matter how the box is stretched. (BoxMesh maps every face to 0..1, which
 * rescales a texture per face and per size.)
 *
 * Per face the UV origin is the face's lower-left corner as seen from
 * outside, u runs to the right and v up (top face: u = +X, v = -Z; bottom
 * face: u = +X, v = +Z), so a tiling texture is never mirrored on the visible
 * side. Each face has its own 4 vertices (hard edges). Tangents are computed.
 *
 * Pure functions, no static state.
 */
final class MetricMesh
{
    /** Face bits for boxBetween()'s `$skipFaces` mask (box faces in generation order). */
    public const FACE_POS_X = 1;
    public const FACE_NEG_X = 2;
    public const FACE_POS_Y = 4;
    public const FACE_NEG_Y = 8;
    public const FACE_POS_Z = 16;
    public const FACE_NEG_Z = 32;

    /** Box centred at the origin. */
    public static function box(float $width, float $height, float $depth, float $uvScale = 1.0): MeshData
    {
        $hw = $width / 2.0;
        $hh = $height / 2.0;
        $hd = $depth / 2.0;
        return self::boxBetween(new Vec3(-$hw, -$hh, -$hd), new Vec3($hw, $hh, $hd), $uvScale);
    }

    /**
     * Axis-aligned box between two corners (any order). `$skipFaces` is a bit mask of
     * FACE_* constants: those faces are not emitted (hidden bottoms of stacked tiles,
     * faces against a wall), saving their 2 triangles and 4 vertices each. The result
     * is then open on those faces. 0 (default) builds all six, byte-identical to before.
     */
    public static function boxBetween(Vec3 $a, Vec3 $b, float $uvScale = 1.0, int $skipFaces = 0): MeshData
    {
        $x0 = min($a->x, $b->x);
        $x1 = max($a->x, $b->x);
        $y0 = min($a->y, $b->y);
        $y1 = max($a->y, $b->y);
        $z0 = min($a->z, $b->z);
        $z1 = max($a->z, $b->z);
        $w = $x1 - $x0;
        $h = $y1 - $y0;
        $d = $z1 - $z0;

        // [origin, right axis, up axis, right length, up length, normal]
        $faces = [
            [[$x1, $y0, $z1], [0, 0, -1], [0, 1, 0], $d, $h, [1, 0, 0]],
            [[$x0, $y0, $z0], [0, 0, 1], [0, 1, 0], $d, $h, [-1, 0, 0]],
            [[$x0, $y1, $z1], [1, 0, 0], [0, 0, -1], $w, $d, [0, 1, 0]],
            [[$x0, $y0, $z0], [1, 0, 0], [0, 0, 1], $w, $d, [0, -1, 0]],
            [[$x0, $y0, $z1], [1, 0, 0], [0, 1, 0], $w, $h, [0, 0, 1]],
            [[$x1, $y0, $z0], [-1, 0, 0], [0, 1, 0], $w, $h, [0, 0, -1]],
        ];

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        $base = 0;
        foreach ($faces as $fi => [$o, $r, $u, $rl, $ul, $n]) {
            if (($skipFaces & (1 << $fi)) !== 0) {
                continue;
            }
            for ($k = 0; $k < 4; $k++) {
                $sr = ($k === 1 || $k === 2) ? $rl : 0.0;
                $su = ($k >= 2) ? $ul : 0.0;
                array_push(
                    $vertices,
                    (float) $o[0] + $r[0] * $sr + $u[0] * $su,
                    (float) $o[1] + $r[1] * $sr + $u[1] * $su,
                    (float) $o[2] + $r[2] * $sr + $u[2] * $su,
                );
                array_push($normals, (float) $n[0], (float) $n[1], (float) $n[2]);
                array_push($uvs, $sr * $uvScale, $su * $uvScale);
            }
            array_push($indices, $base, $base + 1, $base + 2, $base, $base + 2, $base + 3);
            $base += 4;
        }
        return (new MeshData($vertices, $normals, $uvs, $indices))->withComputedTangents();
    }

    /**
     * Flat grid in the XZ plane centred at the origin, normal +Y,
     * UV = (x + width/2, depth/2 - z) * $uvScale.
     */
    public static function plane(float $width, float $depth, int $segmentsX = 1, int $segmentsZ = 1, float $uvScale = 1.0): MeshData
    {
        $sx = max(1, $segmentsX);
        $sz = max(1, $segmentsZ);
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        for ($j = 0; $j <= $sz; $j++) {
            for ($i = 0; $i <= $sx; $i++) {
                $x = $width * $i / $sx;
                $z = $depth * $j / $sz;
                array_push($vertices, $x - $width / 2.0, 0.0, $depth / 2.0 - $z);
                array_push($normals, 0.0, 1.0, 0.0);
                array_push($uvs, $x * $uvScale, $z * $uvScale);
            }
        }
        $row = $sx + 1;
        for ($j = 0; $j < $sz; $j++) {
            for ($i = 0; $i < $sx; $i++) {
                // Rows run from +Z (j = 0) to -Z; CCW seen from +Y.
                $a = $j * $row + $i;
                array_push($indices, $a, $a + 1, $a + $row + 1, $a, $a + $row + 1, $a + $row);
            }
        }
        return (new MeshData($vertices, $normals, $uvs, $indices))->withComputedTangents();
    }
}
