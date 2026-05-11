<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Generates a closed cylinder mesh centered at the origin.
 * Top cap at y = +height/2, bottom cap at y = -height/2.
 * Side normals are radially outward. Cap normals are (0, ±1, 0).
 */
class CylinderMesh
{
    public static function generate(float $radius, float $height, int $segments): MeshData
    {
        return PerfProfiler::section('mesh.generate.cylinder', static fn(): MeshData
            => self::generateImpl($radius, $height, $segments));
    }

    private static function generateImpl(float $radius, float $height, int $segments): MeshData
    {
        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        $halfH = $height / 2.0;

        // ── Side faces ────────────────────────────────────────────────────────
        // Two vertices per segment ring position (bottom and top) × 2 rings
        $sideBase = 0;
        for ($i = 0; $i <= $segments; $i++) {
            $theta = 2.0 * M_PI * $i / $segments;
            $cos = cos($theta);
            $sin = sin($theta);
            $u = (float)$i / $segments;

            // Bottom vertex
            $vertices[] = $radius * $cos;
            $vertices[] = -$halfH;
            $vertices[] = $radius * $sin;
            $normals[]  = $cos;
            $normals[]  = 0.0;
            $normals[]  = $sin;
            $uvs[]      = $u;
            $uvs[]      = 0.0;

            // Top vertex
            $vertices[] = $radius * $cos;
            $vertices[] = $halfH;
            $vertices[] = $radius * $sin;
            $normals[]  = $cos;
            $normals[]  = 0.0;
            $normals[]  = $sin;
            $uvs[]      = $u;
            $uvs[]      = 1.0;
        }

        // Side indices: each segment has 2 triangles
        for ($i = 0; $i < $segments; $i++) {
            $b = $sideBase + $i * 2;
            $indices[] = $b;
            $indices[] = $b + 2;
            $indices[] = $b + 1;
            $indices[] = $b + 1;
            $indices[] = $b + 2;
            $indices[] = $b + 3;
        }

        // ── Top cap ───────────────────────────────────────────────────────────
        $topCenterIdx = (int)(count($vertices) / 3);
        $vertices[] = 0.0;
        $vertices[] = $halfH;
        $vertices[] = 0.0;
        $normals[]  = 0.0;
        $normals[]  = 1.0;
        $normals[]  = 0.0;
        $uvs[]      = 0.5;
        $uvs[]      = 0.5;

        $topRingBase = (int)(count($vertices) / 3);
        for ($i = 0; $i <= $segments; $i++) {
            $theta = 2.0 * M_PI * $i / $segments;
            $cos = cos($theta);
            $sin = sin($theta);
            $vertices[] = $radius * $cos;
            $vertices[] = $halfH;
            $vertices[] = $radius * $sin;
            $normals[]  = 0.0;
            $normals[]  = 1.0;
            $normals[]  = 0.0;
            $uvs[]      = 0.5 + 0.5 * $cos;
            $uvs[]      = 0.5 + 0.5 * $sin;
        }

        for ($i = 0; $i < $segments; $i++) {
            $indices[] = $topCenterIdx;
            $indices[] = $topRingBase + $i + 1;
            $indices[] = $topRingBase + $i;
        }

        // ── Bottom cap ────────────────────────────────────────────────────────
        $botCenterIdx = (int)(count($vertices) / 3);
        $vertices[] = 0.0;
        $vertices[] = -$halfH;
        $vertices[] = 0.0;
        $normals[]  = 0.0;
        $normals[]  = -1.0;
        $normals[]  = 0.0;
        $uvs[]      = 0.5;
        $uvs[]      = 0.5;

        $botRingBase = (int)(count($vertices) / 3);
        for ($i = 0; $i <= $segments; $i++) {
            $theta = 2.0 * M_PI * $i / $segments;
            $cos = cos($theta);
            $sin = sin($theta);
            $vertices[] = $radius * $cos;
            $vertices[] = -$halfH;
            $vertices[] = $radius * $sin;
            $normals[]  = 0.0;
            $normals[]  = -1.0;
            $normals[]  = 0.0;
            $uvs[]      = 0.5 + 0.5 * $cos;
            $uvs[]      = 0.5 - 0.5 * $sin;
        }

        for ($i = 0; $i < $segments; $i++) {
            $indices[] = $botCenterIdx;
            $indices[] = $botRingBase + $i;
            $indices[] = $botRingBase + $i + 1;
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
