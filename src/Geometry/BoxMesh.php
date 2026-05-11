<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Generates a box (cuboid) mesh centered at the origin.
 * Each face has its own 4 vertices for correct per-face normals.
 * 6 faces × 4 vertices = 24 vertices, 6 faces × 2 triangles × 3 indices = 36 indices.
 */
class BoxMesh
{
    public static function generate(float $width, float $height, float $depth): MeshData
    {
        return PerfProfiler::section('mesh.generate.box', static fn(): MeshData
            => self::generateImpl($width, $height, $depth));
    }

    private static function generateImpl(float $width, float $height, float $depth): MeshData
    {
        $hw = $width  / 2.0;
        $hh = $height / 2.0;
        $hd = $depth  / 2.0;

        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        // Each face: 4 corners, normal, and UV 0→1
        $faces = [
            // +X right
            [[ $hw, -$hh, -$hd], [ $hw,  $hh, -$hd], [ $hw,  $hh,  $hd], [ $hw, -$hh,  $hd], [1, 0, 0]],
            // -X left
            [[-$hw, -$hh,  $hd], [-$hw,  $hh,  $hd], [-$hw,  $hh, -$hd], [-$hw, -$hh, -$hd], [-1, 0, 0]],
            // +Y top
            [[-$hw,  $hh, -$hd], [-$hw,  $hh,  $hd], [ $hw,  $hh,  $hd], [ $hw,  $hh, -$hd], [0, 1, 0]],
            // -Y bottom
            [[-$hw, -$hh,  $hd], [-$hw, -$hh, -$hd], [ $hw, -$hh, -$hd], [ $hw, -$hh,  $hd], [0, -1, 0]],
            // +Z front
            [[-$hw, -$hh,  $hd], [ $hw, -$hh,  $hd], [ $hw,  $hh,  $hd], [-$hw,  $hh,  $hd], [0, 0, 1]],
            // -Z back
            [[ $hw, -$hh, -$hd], [-$hw, -$hh, -$hd], [-$hw,  $hh, -$hd], [ $hw,  $hh, -$hd], [0, 0, -1]],
        ];

        $faceUVs = [[0, 0], [1, 0], [1, 1], [0, 1]];

        foreach ($faces as $fi => $face) {
            $base = $fi * 4;
            $normal = $face[4];

            for ($vi = 0; $vi < 4; $vi++) {
                $v = $face[$vi];
                $vertices[] = (float)$v[0];
                $vertices[] = (float)$v[1];
                $vertices[] = (float)$v[2];
                $normals[]  = (float)$normal[0];
                $normals[]  = (float)$normal[1];
                $normals[]  = (float)$normal[2];
                $uvs[]      = (float)$faceUVs[$vi][0];
                $uvs[]      = (float)$faceUVs[$vi][1];
            }

            // Two triangles per face (CCW winding)
            $indices[] = $base;
            $indices[] = $base + 1;
            $indices[] = $base + 2;
            $indices[] = $base;
            $indices[] = $base + 2;
            $indices[] = $base + 3;
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
