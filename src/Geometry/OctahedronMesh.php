<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Octahedron centred at the origin (8 flat triangular faces), matching
 * three.js OctahedronGeometry at detail 0. Vertices are emitted per-face with
 * flat normals so it reads as a crisp gem / star core rather than a blobby
 * 6-vertex sphere. The `detail` subdivision of three.js is not reproduced;
 * a higher-detail octahedron approaches a sphere (use SphereMesh for that).
 */
class OctahedronMesh
{
    /** Unit octahedron: 6 base vertices, 8 faces (three.js base topology). */
    private const BASE = [[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]];
    private const FACES = [[0, 2, 4], [0, 4, 3], [0, 3, 5], [0, 5, 2], [1, 2, 5], [1, 5, 3], [1, 3, 4], [1, 4, 2]];

    public static function generate(float $radius = 1.0): MeshData
    {
        return PerfProfiler::section('mesh.generate.octahedron', static fn(): MeshData => self::generateImpl($radius));
    }

    private static function generateImpl(float $radius): MeshData
    {
        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];
        $index = 0;

        foreach (self::FACES as $face) {
            [$p0, $p1, $p2] = [self::BASE[$face[0]], self::BASE[$face[1]], self::BASE[$face[2]]];

            // Flat face normal = normalize(cross(p1 - p0, p2 - p0)).
            $ux = $p1[0] - $p0[0];
            $uy = $p1[1] - $p0[1];
            $uz = $p1[2] - $p0[2];
            $vx = $p2[0] - $p0[0];
            $vy = $p2[1] - $p0[1];
            $vz = $p2[2] - $p0[2];
            $nx = $uy * $vz - $uz * $vy;
            $ny = $uz * $vx - $ux * $vz;
            $nz = $ux * $vy - $uy * $vx;
            $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz) ?: 1.0;
            $nx /= $len;
            $ny /= $len;
            $nz /= $len;

            foreach ([$p0, $p1, $p2] as $k => $p) {
                $vertices[] = $p[0] * $radius;
                $vertices[] = $p[1] * $radius;
                $vertices[] = $p[2] * $radius;
                $normals[] = $nx;
                $normals[] = $ny;
                $normals[] = $nz;
                $uvs[] = $k === 1 ? 1.0 : 0.0;
                $uvs[] = $k === 2 ? 1.0 : 0.0;
                $indices[] = $index++;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
