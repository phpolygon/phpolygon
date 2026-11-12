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
 * `$topOnly = true` rounds only the four edges of the +Y face (tiles, setts, bars,
 * pavers: flat bottom, crisp vertical edges, soft top rim). The four rim strips meet in
 * mitred groins at the corners; the bottom face is flat and the sides run straight
 * down. $bevel is clamped to the smaller of the half X/Z extent and the height.
 * Vertices then: 4 * 2 * (2 * $segments + 2) + 4 + 4 (side strips, top, bottom).
 * Closed and consistently wound like the full bevel.
 *
 * Vertices (full bevel): 6 * (2 * ($segments + 1))^2. Pure function.
 */
final class BevelBoxMesh
{
    public static function generate(float $width, float $height, float $depth, float $bevel, int $segments = 3, float $uvScale = 1.0, bool $topOnly = false): MeshData
    {
        if ($topOnly) {
            return self::topBevel($width, $height, $depth, $bevel, $segments, $uvScale);
        }
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

    private static function topBevel(float $width, float $height, float $depth, float $bevel, int $segments, float $uvScale): MeshData
    {
        $hw = $width / 2.0;
        $hh = $height / 2.0;
        $hd = $depth / 2.0;
        $r = max(0.0, min($bevel, $hw, $hd, $height));
        if ($r <= 1e-9) {
            return MetricMesh::box($width, $height, $depth, $uvScale);
        }
        $arcSteps = 2 * max(1, $segments);

        // Profile rows bottom to top: [inset, y, normal horizontal part, normal up part, v].
        $rows = [];
        $v = 0.0;
        if ($height - $r > 1e-9) {
            $rows[] = [0.0, -$hh, 1.0, 0.0, 0.0];
            $v = $height - $r;
        }
        $prevInset = 0.0;
        $prevY = $hh - $r;
        for ($k = 0; $k <= $arcSteps; $k++) {
            $a = M_PI / 2.0 * $k / $arcSteps;
            $inset = $r * (1.0 - cos($a));
            $y = $hh - $r + $r * sin($a);
            if ($k > 0) {
                $v += sqrt(($inset - $prevInset) ** 2 + ($y - $prevY) ** 2);
            }
            $rows[] = [$inset, $y, cos($a), sin($a), $v];
            $prevInset = $inset;
            $prevY = $y;
        }

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        // Outward direction (x, z) per side face; right = (dz, -dx).
        foreach ([[0.0, 1.0], [1.0, 0.0], [0.0, -1.0], [-1.0, 0.0]] as [$dx, $dz]) {
            $rx = $dz;
            $rz = -$dx;
            $halfD = $dx !== 0.0 ? $hw : $hd;
            $halfR = $dx !== 0.0 ? $hd : $hw;
            $base = intdiv(count($vertices), 3);
            foreach ($rows as [$t, $y, $nh, $nu, $rv]) {
                foreach ([-1.0, 1.0] as $side) {
                    $along = $side * ($halfR - $t);
                    $out = $halfD - $t;
                    array_push($vertices, $dx * $out + $rx * $along, $y, $dz * $out + $rz * $along);
                    array_push($normals, $dx * $nh, $nu, $dz * $nh);
                    array_push($uvs, ($along + $halfR) * $uvScale, $rv * $uvScale);
                }
            }
            for ($j = 0, $n = count($rows) - 1; $j < $n; $j++) {
                $l = $base + $j * 2;
                array_push($indices, $l, $l + 1, $l + 3, $l, $l + 3, $l + 2);
            }
        }

        // Top face (skipped when the bevel eats the whole top).
        if ($hw - $r > 1e-9 && $hd - $r > 1e-9) {
            $base = intdiv(count($vertices), 3);
            $x0 = -($hw - $r);
            $x1 = $hw - $r;
            $z0 = -($hd - $r);
            $z1 = $hd - $r;
            foreach ([[$x0, $z1], [$x1, $z1], [$x1, $z0], [$x0, $z0]] as [$px, $pz]) {
                array_push($vertices, $px, $hh, $pz);
                array_push($normals, 0.0, 1.0, 0.0);
                array_push($uvs, ($px + $hw) * $uvScale, ($hd - $pz) * $uvScale);
            }
            array_push($indices, $base, $base + 1, $base + 2, $base, $base + 2, $base + 3);
        }

        // Bottom face.
        $base = intdiv(count($vertices), 3);
        foreach ([[-$hw, -$hd], [$hw, -$hd], [$hw, $hd], [-$hw, $hd]] as [$px, $pz]) {
            array_push($vertices, $px, -$hh, $pz);
            array_push($normals, 0.0, -1.0, 0.0);
            array_push($uvs, ($px + $hw) * $uvScale, ($pz + $hd) * $uvScale);
        }
        array_push($indices, $base, $base + 1, $base + 2, $base, $base + 2, $base + 3);

        return (new MeshData($vertices, $normals, $uvs, $indices))->withComputedTangents();
    }
}
