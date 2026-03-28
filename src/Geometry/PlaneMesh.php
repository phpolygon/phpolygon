<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Generates a quad in the XZ plane, centered at the origin.
 * Normal points upward (0, 1, 0).
 *
 * With subdivisions > 1, generates a grid of vertices for GPU wave animation.
 */
class PlaneMesh
{
    public static function generate(float $width, float $depth, int $subdivisions = 1): MeshData
    {
        $hw = $width / 2.0;
        $hd = $depth / 2.0;

        $segsX = max(1, $subdivisions);
        $segsZ = max(1, $subdivisions);

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        for ($iz = 0; $iz <= $segsZ; $iz++) {
            for ($ix = 0; $ix <= $segsX; $ix++) {
                $tx = (float) $ix / $segsX;
                $tz = (float) $iz / $segsZ;

                $vertices[] = -$hw + $tx * $width;
                $vertices[] = 0.0;
                $vertices[] = -$hd + $tz * $depth;

                $normals[] = 0.0;
                $normals[] = 1.0;
                $normals[] = 0.0;

                $uvs[] = $tx;
                $uvs[] = $tz;
            }
        }

        $cols = $segsX + 1;
        for ($iz = 0; $iz < $segsZ; $iz++) {
            for ($ix = 0; $ix < $segsX; $ix++) {
                $tl = $iz * $cols + $ix;
                $tr = $tl + 1;
                $bl = $tl + $cols;
                $br = $bl + 1;

                $indices[] = $tl;
                $indices[] = $tr;
                $indices[] = $br;
                $indices[] = $tl;
                $indices[] = $br;
                $indices[] = $bl;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
