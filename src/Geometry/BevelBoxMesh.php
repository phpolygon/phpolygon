<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Box with rounded (bevelled) edges and corners, centred at the origin.
 *
 * Every face is a grid whose outer rows/columns bend around the edge as a
 * quarter-circle of radius $bevel (cube-to-rounded-box mapping), so the six
 * face grids meet exactly along the edges (positions coincide, weld them with
 * MeshCleanup::weld if you want one shared vertex). Normals are smooth over
 * the bevel and flat on the flat areas. UVs are in world metres of the face
 * plane projection (see MetricMesh), so patterns do not rescale.
 *
 * $segments is the number of segments per 45 degrees of the edge arc, i.e.
 * 2 x $segments per rounded edge: 1 gives a coarse 2-facet chamfer-like
 * edge, 3-4 a smooth roundover. $bevel is clamped to the smallest half
 * extent. A hard single-facet chamfer is not produced here; chamfer a
 * Profile and extrude it for that.
 *
 * Vertices: 6 * (2 * ($segments + 1))^2. Pure function.
 */
final class BevelBoxMesh
{
    public static function generate(float $width, float $height, float $depth, float $bevel, int $segments = 3, float $uvScale = 1.0): MeshData
    {
        $half = [$width / 2.0, $height / 2.0, $depth / 2.0];
        $r = max(0.0, min($bevel, $half[0], $half[1], $half[2]));
        if ($r <= 1e-9) {
            return MetricMesh::box($width, $height, $depth, $uvScale);
        }
        $segments = max(1, $segments);
        $inner = [$half[0] - $r, $half[1] - $r, $half[2] - $r];

        // 1D samples: [centre offset sign, tan(alpha)] for one tangent axis.
        $samples = [];
        for ($k = 0; $k <= $segments; $k++) {
            $samples[] = [-1.0, -tan(M_PI / 4.0 * (1.0 - $k / $segments))];
        }
        for ($k = 0; $k <= $segments; $k++) {
            $samples[] = [1.0, tan(M_PI / 4.0 * $k / $segments)];
        }
        $n = count($samples);

        // [normal axis, normal sign, right axis, right sign, up axis, up sign]; right x up = normal.
        $faces = [
            [0, 1.0, 2, -1.0, 1, 1.0],
            [0, -1.0, 2, 1.0, 1, 1.0],
            [1, 1.0, 0, 1.0, 2, -1.0],
            [1, -1.0, 0, 1.0, 2, 1.0],
            [2, 1.0, 0, 1.0, 1, 1.0],
            [2, -1.0, 0, -1.0, 1, 1.0],
        ];

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        foreach ($faces as $fi => [$na, $ns, $ra, $rs, $ua, $us]) {
            foreach ($samples as $j => [$cu, $tu]) {
                foreach ($samples as $i => [$cr, $tr]) {
                    $d = [0.0, 0.0, 0.0];
                    $d[$na] = $ns;
                    $d[$ra] = $rs * $tr;
                    $d[$ua] = $us * $tu;
                    $len = sqrt($d[0] * $d[0] + $d[1] * $d[1] + $d[2] * $d[2]);
                    $d = [$d[0] / $len, $d[1] / $len, $d[2] / $len];

                    $c = [0.0, 0.0, 0.0];
                    $c[$na] = $ns * $inner[$na];
                    $c[$ra] = $rs * $cr * $inner[$ra];
                    $c[$ua] = $us * $cu * $inner[$ua];

                    $p = [$c[0] + $r * $d[0], $c[1] + $r * $d[1], $c[2] + $r * $d[2]];
                    array_push($vertices, $p[0], $p[1], $p[2]);
                    array_push($normals, $d[0], $d[1], $d[2]);
                    array_push(
                        $uvs,
                        ($rs * $p[$ra] + $half[$ra]) * $uvScale,
                        ($us * $p[$ua] + $half[$ua]) * $uvScale,
                    );
                }
            }
            $base = $fi * $n * $n;
            for ($j = 0; $j < $n - 1; $j++) {
                for ($i = 0; $i < $n - 1; $i++) {
                    $a = $base + $j * $n + $i;
                    array_push($indices, $a, $a + 1, $a + $n + 1, $a, $a + $n + 1, $a + $n);
                }
            }
        }
        return (new MeshData($vertices, $normals, $uvs, $indices))->withComputedTangents();
    }
}
