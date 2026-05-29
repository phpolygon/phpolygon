<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Torus (ring) centred at the origin, lying in the XY plane. Vertex layout and
 * winding mirror three.js TorusGeometry(radius, tube, radialSegments,
 * tubularSegments) so geometry imported from a react-three-fiber / three.js
 * prototype keeps the same shape and orientation.
 *
 *   radius          distance from the centre to the centre of the tube
 *   tube            radius of the tube
 *   radialSegments  segments around the tube cross-section
 *   tubularSegments segments around the main ring
 */
class TorusMesh
{
    public static function generate(
        float $radius = 1.0,
        float $tube = 0.4,
        int $radialSegments = 12,
        int $tubularSegments = 24,
    ): MeshData {
        return PerfProfiler::section('mesh.generate.torus', static fn(): MeshData
            => self::generateImpl($radius, $tube, max(3, $radialSegments), max(3, $tubularSegments)));
    }

    private static function generateImpl(float $radius, float $tube, int $radialSegments, int $tubularSegments): MeshData
    {
        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        for ($j = 0; $j <= $radialSegments; $j++) {
            for ($i = 0; $i <= $tubularSegments; $i++) {
                $u = (float) $i / $tubularSegments * 2.0 * M_PI;
                $v = (float) $j / $radialSegments * 2.0 * M_PI;

                $x = ($radius + $tube * cos($v)) * cos($u);
                $y = ($radius + $tube * cos($v)) * sin($u);
                $z = $tube * sin($v);

                $vertices[] = $x;
                $vertices[] = $y;
                $vertices[] = $z;

                // Normal points away from the tube's centre circle.
                $nx = $x - $radius * cos($u);
                $ny = $y - $radius * sin($u);
                $nz = $z;
                $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz) ?: 1.0;
                $normals[] = $nx / $len;
                $normals[] = $ny / $len;
                $normals[] = $nz / $len;

                $uvs[] = (float) $i / $tubularSegments;
                $uvs[] = (float) $j / $radialSegments;
            }
        }

        for ($j = 1; $j <= $radialSegments; $j++) {
            for ($i = 1; $i <= $tubularSegments; $i++) {
                $a = ($tubularSegments + 1) * $j + $i - 1;
                $b = ($tubularSegments + 1) * ($j - 1) + $i - 1;
                $c = ($tubularSegments + 1) * ($j - 1) + $i;
                $d = ($tubularSegments + 1) * $j + $i;

                $indices[] = $a;
                $indices[] = $b;
                $indices[] = $d;
                $indices[] = $b;
                $indices[] = $c;
                $indices[] = $d;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
