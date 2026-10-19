<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Loft a closed, smooth skin through a stack of {@see LoftRing} cross-sections
 * along +Y – the shape tool for organic forms a {@see LatheMesh} cannot make:
 * sections may be elliptical, fuller on one side than the other, and shifted off
 * the axis (a thigh with a front bulge, a shoe, a neck leaning forward).
 *
 * Rings are given BOTTOM to TOP (like a LatheMesh profile). Both ends are closed
 * with rounded caps: $capBottom / $capTop is how far the cap bulges past the end
 * ring (0 = a flat lid), shaped as a quarter-ellipse over $capSteps rings.
 *
 * Shading is smooth (area-weighted vertex normals, the UV seam shares its
 * normal); the triangle winding matches {@see LatheMesh}, so lofts and lathes
 * can be mixed in one model. Turn or move the result with the usual mesh
 * transforms (e.g. the procedural-mesh `transform` node) to loft along another
 * axis.
 */
final class EllipseLoftMesh
{
    /**
     * @param list<LoftRing> $rings Cross-sections, bottom to top (at least 1).
     */
    public static function generate(
        array $rings,
        int $segments = 16,
        float $capBottom = 0.0,
        float $capTop = 0.0,
        int $capSteps = 4,
    ): MeshData {
        return PerfProfiler::section('mesh.generate.ellipse_loft', static fn(): MeshData
            => self::generateImpl($rings, max(3, $segments), max(0.0, $capBottom), max(0.0, $capTop), max(1, $capSteps)));
    }

    /**
     * @param list<LoftRing> $rings
     */
    private static function generateImpl(array $rings, int $segments, float $capBottom, float $capTop, int $capSteps): MeshData
    {
        if ($rings === []) {
            return new MeshData(vertices: [], normals: [], uvs: [], indices: []);
        }

        // Rows bottom → top: bottom cap (pole first), the rings, top cap (pole last).
        $rows = [];
        $first = $rings[0];
        for ($k = $capSteps; $k >= 1; $k--) {
            $a = M_PI_2 * $k / $capSteps;
            $rows[] = $first->scaled(cos($a), $first->y - $capBottom * sin($a));
        }
        foreach ($rings as $ring) {
            $rows[] = $ring;
        }
        $last = $rings[count($rings) - 1];
        for ($k = 1; $k <= $capSteps; $k++) {
            $a = M_PI_2 * $k / $capSteps;
            $rows[] = $last->scaled(cos($a), $last->y + $capTop * sin($a));
        }

        $rowCount = count($rows);
        $cols = $segments + 1; // the seam column is duplicated for the UV wrap

        $vertices = [];
        $uvs = [];
        for ($j = 0; $j < $cols; $j++) {
            $phi = 2.0 * M_PI * ($j % $segments) / $segments;
            foreach ($rows as $i => $row) {
                [$x, $z] = $row->pointAt($phi);
                array_push($vertices, $x, $row->y, $z);
                array_push($uvs, $j / $segments, $i / max(1, $rowCount - 1));
            }
        }

        // Azimuth runs from +Z toward +X – the opposite sense to LatheMesh – so
        // the quad is split the other way round to keep LatheMesh's winding.
        $indices = [];
        for ($j = 0; $j < $segments; $j++) {
            for ($i = 0; $i < $rowCount - 1; $i++) {
                $a = $j * $rowCount + $i;
                $b = ($j + 1) * $rowCount + $i;
                $c = ($j + 1) * $rowCount + $i + 1;
                $d = $j * $rowCount + $i + 1;
                array_push($indices, $a, $c, $b, $a, $d, $c);
            }
        }

        $normals = self::smoothNormals($vertices, $indices);
        // One normal across the seam: the duplicated column shares column 0's.
        $seam = $segments * $rowCount * 3;
        for ($o = 0; $o < $rowCount * 3; $o += 3) {
            for ($k = 0; $k < 3; $k++) {
                $sum = $normals[$o + $k] + $normals[$seam + $o + $k];
                $normals[$o + $k] = $sum;
                $normals[$seam + $o + $k] = $sum;
            }
        }

        return new MeshData($vertices, self::normalize($normals), $uvs, $indices);
    }

    /**
     * Area-weighted, UNnormalised vertex normals. In LatheMesh winding the
     * face cross product (b−a)×(c−a) points inward, hence the negation.
     *
     * @param array<int, float> $vertices
     * @param array<int, int> $indices
     * @return array<int, float>
     */
    private static function smoothNormals(array $vertices, array $indices): array
    {
        $normals = array_fill(0, count($vertices), 0.0);
        for ($t = 0, $n = count($indices); $t < $n; $t += 3) {
            $ia = $indices[$t] * 3;
            $ib = $indices[$t + 1] * 3;
            $ic = $indices[$t + 2] * 3;
            $ux = $vertices[$ib] - $vertices[$ia];
            $uy = $vertices[$ib + 1] - $vertices[$ia + 1];
            $uz = $vertices[$ib + 2] - $vertices[$ia + 2];
            $wx = $vertices[$ic] - $vertices[$ia];
            $wy = $vertices[$ic + 1] - $vertices[$ia + 1];
            $wz = $vertices[$ic + 2] - $vertices[$ia + 2];
            $fx = -($uy * $wz - $uz * $wy);
            $fy = -($uz * $wx - $ux * $wz);
            $fz = -($ux * $wy - $uy * $wx);
            foreach ([$ia, $ib, $ic] as $o) {
                $normals[$o] += $fx;
                $normals[$o + 1] += $fy;
                $normals[$o + 2] += $fz;
            }
        }

        return $normals;
    }

    /**
     * @param array<int, float> $normals
     * @return array<int, float>
     */
    private static function normalize(array $normals): array
    {
        for ($o = 0, $n = count($normals); $o < $n; $o += 3) {
            $len = sqrt($normals[$o] ** 2 + $normals[$o + 1] ** 2 + $normals[$o + 2] ** 2);
            if ($len > 1e-12) {
                $normals[$o] /= $len;
                $normals[$o + 1] /= $len;
                $normals[$o + 2] /= $len;
            } else {
                // A degenerate fan (a zero-size cap pole): point along the axis.
                $normals[$o] = 0.0;
                $normals[$o + 1] = 1.0;
                $normals[$o + 2] = 0.0;
            }
        }

        return $normals;
    }
}
