<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Generates a wedge (triangular prism) mesh for gable wall fills.
 *
 * Cross-section is a triangle with configurable peak position:
 *   - Base at Y = -1 (width along Z: -1 to +1)
 *   - Peak at Y = +1, Z = peakZ (default 0 = isosceles, -1 = right triangle)
 *   - Depth along X: -1 to +1
 *
 * Same coordinate convention as BoxMesh: centered at origin, base size 2×2×2.
 *
 * peakZ=0 (symmetric):     peakZ=-1 (right triangle):
 *      /\                   |\
 *     /  \                  | \
 *    /    \                 |  \
 *   /______\                |___\
 */
class WedgeMesh
{
    /**
     * @param float $peakZ Z-position of the peak (-1 to +1). 0 = centered (isosceles), -1 or +1 = right triangle.
     */
    public static function generate(float $peakZ = 0.0): MeshData
    {
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        // 6 unique positions:
        // Front (X=+1): BL(+1,-1,+1), BR(+1,-1,-1), Peak(+1,+1,peakZ)
        // Back  (X=-1): BL(-1,-1,-1), BR(-1,-1,+1), Peak(-1,+1,peakZ)

        $pz = $peakZ;

        // --- Front triangle face (X = +1) ---
        $fn = [1.0, 0.0, 0.0];
        self::addVertex($vertices, $normals, $uvs, 1, -1, 1, $fn, 0.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, 1, -1, -1, $fn, 1.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, 1, 1, $pz, $fn, ($pz + 1.0) * 0.5, 1.0);
        $indices[] = 0; $indices[] = 1; $indices[] = 2;

        // --- Back triangle face (X = -1) ---
        $bn = [-1.0, 0.0, 0.0];
        self::addVertex($vertices, $normals, $uvs, -1, -1, -1, $bn, 0.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, -1, -1, 1, $bn, 1.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, -1, 1, $pz, $bn, ($pz + 1.0) * 0.5, 1.0);
        $indices[] = 3; $indices[] = 4; $indices[] = 5;

        // --- Bottom face (Y = -1) ---
        $botn = [0.0, -1.0, 0.0];
        self::addVertex($vertices, $normals, $uvs, -1, -1, 1, $botn, 0.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, -1, -1, -1, $botn, 0.0, 1.0);
        self::addVertex($vertices, $normals, $uvs, 1, -1, -1, $botn, 1.0, 1.0);
        self::addVertex($vertices, $normals, $uvs, 1, -1, 1, $botn, 1.0, 0.0);
        $indices[] = 6; $indices[] = 7; $indices[] = 8;
        $indices[] = 6; $indices[] = 8; $indices[] = 9;

        // --- Left slope (+Z side → peak) ---
        $ln = self::normalizeArr([0.0, -(($pz) - 1.0), 2.0]);
        self::addVertex($vertices, $normals, $uvs, 1, -1, 1, $ln, 0.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, -1, -1, 1, $ln, 1.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, -1, 1, $pz, $ln, 1.0, 1.0);
        self::addVertex($vertices, $normals, $uvs, 1, 1, $pz, $ln, 0.0, 1.0);
        $indices[] = 10; $indices[] = 11; $indices[] = 12;
        $indices[] = 10; $indices[] = 12; $indices[] = 13;

        // --- Right slope (-Z side → peak) ---
        $rn = self::normalizeArr([0.0, -(($pz) - (-1.0)), -2.0]);
        self::addVertex($vertices, $normals, $uvs, -1, -1, -1, $rn, 0.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, 1, -1, -1, $rn, 1.0, 0.0);
        self::addVertex($vertices, $normals, $uvs, 1, 1, $pz, $rn, 1.0, 1.0);
        self::addVertex($vertices, $normals, $uvs, -1, 1, $pz, $rn, 0.0, 1.0);
        $indices[] = 14; $indices[] = 15; $indices[] = 16;
        $indices[] = 14; $indices[] = 16; $indices[] = 17;

        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    /**
     * @param float[] &$v
     * @param float[] &$n
     * @param float[] &$u
     * @param float[] $normal
     */
    private static function addVertex(
        array &$v, array &$n, array &$u,
        float $x, float $y, float $z,
        array $normal,
        float $uvX, float $uvY,
    ): void {
        $v[] = $x; $v[] = $y; $v[] = $z;
        $n[] = $normal[0]; $n[] = $normal[1]; $n[] = $normal[2];
        $u[] = $uvX; $u[] = $uvY;
    }

    /**
     * @param float[] $v
     * @return float[]
     */
    private static function normalizeArr(array $v): array
    {
        $len = sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);
        if ($len < 1e-8) return [0.0, 1.0, 0.0];
        return [$v[0] / $len, $v[1] / $len, $v[2] / $len];
    }
}
