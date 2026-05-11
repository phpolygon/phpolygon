<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Generates a UV sphere mesh centered at the origin.
 * Normals are the normalized position vectors (radius = 1 surface).
 */
class SphereMesh
{
    public static function generate(float $radius, int $stacks, int $slices): MeshData
    {
        return PerfProfiler::section('mesh.generate.sphere', static fn(): MeshData
            => self::generateImpl($radius, $stacks, $slices));
    }

    private static function generateImpl(float $radius, int $stacks, int $slices): MeshData
    {
        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        for ($i = 0; $i <= $stacks; $i++) {
            $phi = M_PI * $i / $stacks; // 0 → PI (top to bottom)
            $sinPhi = sin($phi);
            $cosPhi = cos($phi);

            for ($j = 0; $j <= $slices; $j++) {
                $theta = 2.0 * M_PI * $j / $slices; // 0 → 2PI
                $sinTheta = sin($theta);
                $cosTheta = cos($theta);

                $x = $cosTheta * $sinPhi;
                $y = $cosPhi;
                $z = $sinTheta * $sinPhi;

                $vertices[] = $radius * $x;
                $vertices[] = $radius * $y;
                $vertices[] = $radius * $z;
                $normals[]  = $x;
                $normals[]  = $y;
                $normals[]  = $z;
                $uvs[]      = (float)$j / $slices;
                $uvs[]      = (float)$i / $stacks;
            }
        }

        for ($i = 0; $i < $stacks; $i++) {
            for ($j = 0; $j < $slices; $j++) {
                $a = $i * ($slices + 1) + $j;
                $b = $a + ($slices + 1);

                $indices[] = $a;
                $indices[] = $b;
                $indices[] = $a + 1;
                $indices[] = $b;
                $indices[] = $b + 1;
                $indices[] = $a + 1;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
