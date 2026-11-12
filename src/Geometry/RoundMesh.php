<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec2;

/**
 * Round bodies for the procedural geometry kit: cylinder, sphere, lathe and
 * torus on any of the three axes, with metric UVs (one UV unit = one metre times
 * $uvScale) and tangents.
 *
 * Unlike CylinderMesh / SphereMesh / LatheMesh / TorusMesh these skip
 * PerfProfiler::section (shared static state) and are pure, so they are safe
 * inside a worker thread. The older generators are unchanged.
 *
 * Every body is centred at the origin; `$axis` ('x'|'y'|'z') is the body's axis of
 * symmetry. Winding is outward-facing (positive signed volume). Normals are smooth
 * around the body; cylinder caps are flat with their own vertices (hard rim).
 *
 * UVs: u runs along the circumference in metres (at the vertex's own radius, so a
 * texture keeps its scale on a cone), v along the surface in metres (cylinder:
 * along the axis; lathe: along the profile; sphere/torus: along the meridian).
 * The seam duplicates vertices (u = 0 and u = circumference).
 */
final class RoundMesh
{
    /**
     * Closed or open cylinder of $length along $axis.
     */
    public static function cylinder(
        float $radius,
        float $length,
        string $axis = 'y',
        int $sides = 24,
        bool $caps = true,
        float $uvScale = 1.0,
    ): MeshData {
        $sides = max(3, $sides);
        $half = $length / 2.0;
        $circ = 2.0 * M_PI * $radius;
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        for ($i = 0; $i <= $sides; $i++) {
            $t = 2.0 * M_PI * $i / $sides;
            $c = cos($t);
            $s = sin($t);
            $u = $circ * $i / $sides * $uvScale;
            array_push($vertices, $radius * $c, -$half, $radius * $s, $radius * $c, $half, $radius * $s);
            array_push($normals, $c, 0.0, $s, $c, 0.0, $s);
            array_push($uvs, $u, 0.0, $u, $length * $uvScale);
        }
        for ($i = 0; $i < $sides; $i++) {
            $b = $i * 2;
            array_push($indices, $b, $b + 1, $b + 2, $b + 1, $b + 3, $b + 2);
        }

        if ($caps) {
            foreach ([[$half, 1.0], [-$half, -1.0]] as [$y, $ny]) {
                $centre = intdiv(count($vertices), 3);
                array_push($vertices, 0.0, $y, 0.0);
                array_push($normals, 0.0, $ny, 0.0);
                array_push($uvs, 0.0, 0.0);
                for ($i = 0; $i <= $sides; $i++) {
                    $t = 2.0 * M_PI * $i / $sides;
                    $c = cos($t);
                    $s = sin($t);
                    array_push($vertices, $radius * $c, $y, $radius * $s);
                    array_push($normals, 0.0, $ny, 0.0);
                    array_push($uvs, $radius * $c * $uvScale, $radius * $s * $uvScale);
                }
                for ($i = 0; $i < $sides; $i++) {
                    if ($ny > 0.0) {
                        array_push($indices, $centre, $centre + 2 + $i, $centre + 1 + $i);
                    } else {
                        array_push($indices, $centre, $centre + 1 + $i, $centre + 2 + $i);
                    }
                }
            }
        }

        return self::finish($vertices, $normals, $uvs, $indices, $axis);
    }

    /**
     * UV sphere: $stacks rings from pole to pole, $slices around the axis.
     */
    public static function sphere(float $radius, int $stacks = 12, int $slices = 24, string $axis = 'y', float $uvScale = 1.0): MeshData
    {
        $stacks = max(2, $stacks);
        $slices = max(3, $slices);
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        for ($i = 0; $i <= $stacks; $i++) {
            $phi = M_PI * $i / $stacks; // 0 = +axis pole, PI = -axis pole
            $sp = sin($phi);
            $cp = cos($phi);
            for ($j = 0; $j <= $slices; $j++) {
                $t = 2.0 * M_PI * $j / $slices;
                $x = cos($t) * $sp;
                $z = sin($t) * $sp;
                array_push($vertices, $radius * $x, $radius * $cp, $radius * $z);
                array_push($normals, $x, $cp, $z);
                array_push($uvs, $radius * $sp * $t * $uvScale, $radius * (M_PI - $phi) * $uvScale);
            }
        }
        $row = $slices + 1;
        for ($i = 0; $i < $stacks; $i++) {
            for ($j = 0; $j < $slices; $j++) {
                $a = $i * $row + $j;
                $b = $a + $row;
                array_push($indices, $a, $a + 1, $b, $b, $a + 1, $b + 1);
            }
        }
        return self::finish($vertices, $normals, $uvs, $indices, $axis);
    }

    /**
     * Solid of revolution: the profile (x = radius >= 0, y = position along the axis)
     * rotated around $axis. No automatic caps (end the profile at x = 0 for a closed
     * solid). Smooth normals; repeat a profile point for a hard crease. Profile order
     * decides the facing: outside-up with growing radius faces outward.
     *
     * @param list<Vec2> $profile >= 2 points
     */
    public static function lathe(array $profile, int $segments = 24, string $axis = 'y', float $uvScale = 1.0): MeshData
    {
        $segments = max(3, $segments);
        $profile = array_values($profile);
        $n = count($profile);
        if ($n < 2) {
            return new MeshData([], [], [], []);
        }

        $pn = [];
        $v = [];
        $run = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $tx = $ty = 0.0;
            if ($i > 0) {
                $tx += $profile[$i]->x - $profile[$i - 1]->x;
                $ty += $profile[$i]->y - $profile[$i - 1]->y;
                $run += sqrt(($profile[$i]->x - $profile[$i - 1]->x) ** 2 + ($profile[$i]->y - $profile[$i - 1]->y) ** 2);
            }
            if ($i < $n - 1) {
                $tx += $profile[$i + 1]->x - $profile[$i]->x;
                $ty += $profile[$i + 1]->y - $profile[$i]->y;
            }
            $len = sqrt($tx * $tx + $ty * $ty);
            $pn[] = $len < 1e-9 ? [0.0, 1.0] : [$ty / $len, -$tx / $len];
            $v[] = $run * $uvScale;
        }

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        for ($s = 0; $s <= $segments; $s++) {
            $t = 2.0 * M_PI * $s / $segments;
            $c = cos($t);
            $sn = sin($t);
            for ($i = 0; $i < $n; $i++) {
                $r = $profile[$i]->x;
                array_push($vertices, $r * $c, $profile[$i]->y, $r * $sn);
                array_push($normals, $pn[$i][0] * $c, $pn[$i][1], $pn[$i][0] * $sn);
                array_push($uvs, $r * $t * $uvScale, $v[$i]);
            }
        }
        for ($s = 0; $s < $segments; $s++) {
            for ($i = 0; $i < $n - 1; $i++) {
                $a = $s * $n + $i;
                $b = ($s + 1) * $n + $i;
                array_push($indices, $a, $b + 1, $b, $a, $a + 1, $b + 1);
            }
        }
        return self::finish($vertices, $normals, $uvs, $indices, $axis);
    }

    /**
     * Torus (ring) around $axis: $major = distance from the centre to the tube centre,
     * $minor = tube radius.
     */
    public static function torus(
        float $major,
        float $minor,
        int $tubularSegments = 32,
        int $radialSegments = 12,
        string $axis = 'y',
        float $uvScale = 1.0,
    ): MeshData {
        $tubularSegments = max(3, $tubularSegments);
        $radialSegments = max(3, $radialSegments);
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        for ($j = 0; $j <= $radialSegments; $j++) {
            $b = 2.0 * M_PI * $j / $radialSegments;
            $cb = cos($b);
            $sb = sin($b);
            for ($i = 0; $i <= $tubularSegments; $i++) {
                $a = 2.0 * M_PI * $i / $tubularSegments;
                $ca = cos($a);
                $sa = sin($a);
                $d = $major + $minor * $cb;
                array_push($vertices, $d * $ca, $minor * $sb, $d * $sa);
                array_push($normals, $cb * $ca, $sb, $cb * $sa);
                array_push($uvs, $major * $a * $uvScale, $minor * $b * $uvScale);
            }
        }
        $row = $tubularSegments + 1;
        for ($j = 0; $j < $radialSegments; $j++) {
            for ($i = 0; $i < $tubularSegments; $i++) {
                $a = $j * $row + $i;
                $b = $a + $row;
                array_push($indices, $a, $b, $a + 1, $a + 1, $b, $b + 1);
            }
        }
        return self::finish($vertices, $normals, $uvs, $indices, $axis);
    }

    /**
     * @param list<float> $vertices
     * @param list<float> $normals
     * @param list<float> $uvs
     * @param list<int> $indices
     */
    private static function finish(array $vertices, array $normals, array $uvs, array $indices, string $axis): MeshData
    {
        $mesh = (new MeshData($vertices, $normals, $uvs, $indices))->withComputedTangents();
        return $mesh->axisRotated(1, MeshData::axisIndex($axis));
    }
}
