<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Bake;

use PHPolygon\Fieldtracing\Volume\SdfVolume;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Vec3;

/**
 * Fallback SDF source for {@see MeshData} that does not originate from analytic
 * primitives (composite buildings, terrain). Rasterises a *signed* distance
 * field: unsigned distance to the nearest triangle, signed by an inside/outside
 * test (ray-crossing parity against the closed surface).
 *
 * Prefer {@see SdfVolumeBaker} on analytic fields — it is exact and far cheaper.
 * This brute-force baker is O(cells * triangles); it is meant to run once at
 * load/build time on a worker thread (SdfBakeSystem), never per frame.
 */
final class MeshSdfBaker
{
    /**
     * @param int   $resolution Samples along the longest axis (>= 2).
     * @param float $padding    World-space margin added around the mesh AABB.
     */
    public static function bake(MeshData $mesh, int $resolution, float $padding = 0.5): SdfVolume
    {
        if ($resolution < 2) {
            throw new \InvalidArgumentException('resolution must be >= 2.');
        }
        if ($mesh->triangleCount() === 0) {
            throw new \InvalidArgumentException('Cannot bake an SDF from a mesh with no triangles.');
        }

        $v = $mesh->vertices;
        $idx = $mesh->indices;

        // AABB of the mesh.
        $minX = $minY = $minZ = INF;
        $maxX = $maxY = $maxZ = -INF;
        $vertCount = (int)(count($v) / 3);
        for ($i = 0; $i < $vertCount; $i++) {
            $x = $v[$i * 3];
            $y = $v[$i * 3 + 1];
            $z = $v[$i * 3 + 2];
            $minX = min($minX, $x); $maxX = max($maxX, $x);
            $minY = min($minY, $y); $maxY = max($maxY, $y);
            $minZ = min($minZ, $z); $maxZ = max($maxZ, $z);
        }
        $minX -= $padding; $minY -= $padding; $minZ -= $padding;
        $maxX += $padding; $maxY += $padding; $maxZ += $padding;

        $ex = max($maxX - $minX, 1e-6);
        $ey = max($maxY - $minY, 1e-6);
        $ez = max($maxZ - $minZ, 1e-6);
        $longest = max($ex, $ey, $ez);
        $cellSize = $longest / ($resolution - 1);
        $nx = max(2, (int)round($ex / $cellSize) + 1);
        $ny = max(2, (int)round($ey / $cellSize) + 1);
        $nz = max(2, (int)round($ez / $cellSize) + 1);

        $triCount = (int)(count($idx) / 3);
        // Flatten triangle vertices once: 9 floats per triangle.
        $tris = [];
        for ($t = 0; $t < $triCount; $t++) {
            $a = $idx[$t * 3] * 3;
            $b = $idx[$t * 3 + 1] * 3;
            $c = $idx[$t * 3 + 2] * 3;
            $tris[] = [
                $v[$a], $v[$a + 1], $v[$a + 2],
                $v[$b], $v[$b + 1], $v[$b + 2],
                $v[$c], $v[$c + 1], $v[$c + 2],
            ];
        }

        // Fixed non-axis-aligned ray direction for the parity test; avoids
        // grazing the axis-aligned faces a box mesh produces.
        $dx = 0.5615; $dy = 0.4339; $dz = 0.7036;

        $data = [];
        for ($iz = 0; $iz < $nz; $iz++) {
            $pz = $minZ + $iz * $cellSize;
            for ($iy = 0; $iy < $ny; $iy++) {
                $py = $minY + $iy * $cellSize;
                for ($ix = 0; $ix < $nx; $ix++) {
                    $px = $minX + $ix * $cellSize;

                    $best = INF;
                    $crossings = 0;
                    foreach ($tris as $tri) {
                        $d = self::pointTriangleDistanceSq($px, $py, $pz, $tri);
                        if ($d < $best) {
                            $best = $d;
                        }
                        if (self::rayHitsTriangle($px, $py, $pz, $dx, $dy, $dz, $tri)) {
                            $crossings++;
                        }
                    }

                    $dist = sqrt($best);
                    if (($crossings & 1) === 1) {
                        $dist = -$dist; // odd crossings => inside
                    }
                    $data[] = $dist;
                }
            }
        }

        return new SdfVolume($nx, $ny, $nz, new Vec3($minX, $minY, $minZ), $cellSize, $data);
    }

    /**
     * Squared distance from point (px,py,pz) to a triangle, via Ericson's
     * closest-point-on-triangle (Real-Time Collision Detection).
     *
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float,8:float} $tri
     */
    private static function pointTriangleDistanceSq(float $px, float $py, float $pz, array $tri): float
    {
        $ax = $tri[0]; $ay = $tri[1]; $az = $tri[2];
        $bx = $tri[3]; $by = $tri[4]; $bz = $tri[5];
        $cx = $tri[6]; $cy = $tri[7]; $cz = $tri[8];

        $abx = $bx - $ax; $aby = $by - $ay; $abz = $bz - $az;
        $acx = $cx - $ax; $acy = $cy - $ay; $acz = $cz - $az;
        $apx = $px - $ax; $apy = $py - $ay; $apz = $pz - $az;

        $d1 = $abx * $apx + $aby * $apy + $abz * $apz;
        $d2 = $acx * $apx + $acy * $apy + $acz * $apz;
        if ($d1 <= 0.0 && $d2 <= 0.0) {
            return self::distSq($px, $py, $pz, $ax, $ay, $az);
        }

        $bpx = $px - $bx; $bpy = $py - $by; $bpz = $pz - $bz;
        $d3 = $abx * $bpx + $aby * $bpy + $abz * $bpz;
        $d4 = $acx * $bpx + $acy * $bpy + $acz * $bpz;
        if ($d3 >= 0.0 && $d4 <= $d3) {
            return self::distSq($px, $py, $pz, $bx, $by, $bz);
        }

        $vc = $d1 * $d4 - $d3 * $d2;
        if ($vc <= 0.0 && $d1 >= 0.0 && $d3 <= 0.0) {
            $v = $d1 / ($d1 - $d3);
            return self::distSq($px, $py, $pz, $ax + $v * $abx, $ay + $v * $aby, $az + $v * $abz);
        }

        $cpx = $px - $cx; $cpy = $py - $cy; $cpz = $pz - $cz;
        $d5 = $abx * $cpx + $aby * $cpy + $abz * $cpz;
        $d6 = $acx * $cpx + $acy * $cpy + $acz * $cpz;
        if ($d6 >= 0.0 && $d5 <= $d6) {
            return self::distSq($px, $py, $pz, $cx, $cy, $cz);
        }

        $vb = $d5 * $d2 - $d1 * $d6;
        if ($vb <= 0.0 && $d2 >= 0.0 && $d6 <= 0.0) {
            $w = $d2 / ($d2 - $d6);
            return self::distSq($px, $py, $pz, $ax + $w * $acx, $ay + $w * $acy, $az + $w * $acz);
        }

        $va = $d3 * $d6 - $d5 * $d4;
        if ($va <= 0.0 && ($d4 - $d3) >= 0.0 && ($d5 - $d6) >= 0.0) {
            $w = ($d4 - $d3) / (($d4 - $d3) + ($d5 - $d6));
            return self::distSq(
                $px, $py, $pz,
                $bx + $w * ($cx - $bx), $by + $w * ($cy - $by), $bz + $w * ($cz - $bz)
            );
        }

        // Interior of the triangle face.
        $denom = 1.0 / ($va + $vb + $vc);
        $v = $vb * $denom;
        $w = $vc * $denom;
        $qx = $ax + $abx * $v + $acx * $w;
        $qy = $ay + $aby * $v + $acy * $w;
        $qz = $az + $abz * $v + $acz * $w;
        return self::distSq($px, $py, $pz, $qx, $qy, $qz);
    }

    /**
     * Möller–Trumbore ray/triangle test; returns true on a forward (t > eps)
     * intersection. Used to count crossings for the inside/outside parity test.
     *
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float,8:float} $tri
     */
    private static function rayHitsTriangle(
        float $ox, float $oy, float $oz,
        float $dx, float $dy, float $dz,
        array $tri
    ): bool {
        $eps = 1e-9;
        $ax = $tri[0]; $ay = $tri[1]; $az = $tri[2];

        $e1x = $tri[3] - $ax; $e1y = $tri[4] - $ay; $e1z = $tri[5] - $az;
        $e2x = $tri[6] - $ax; $e2y = $tri[7] - $ay; $e2z = $tri[8] - $az;

        // h = dir × e2
        $hx = $dy * $e2z - $dz * $e2y;
        $hy = $dz * $e2x - $dx * $e2z;
        $hz = $dx * $e2y - $dy * $e2x;
        $det = $e1x * $hx + $e1y * $hy + $e1z * $hz;
        if ($det > -$eps && $det < $eps) {
            return false; // parallel
        }
        $f = 1.0 / $det;

        $sx = $ox - $ax; $sy = $oy - $ay; $sz = $oz - $az;
        $u = $f * ($sx * $hx + $sy * $hy + $sz * $hz);
        if ($u < 0.0 || $u > 1.0) {
            return false;
        }

        // q = s × e1
        $qx = $sy * $e1z - $sz * $e1y;
        $qy = $sz * $e1x - $sx * $e1z;
        $qz = $sx * $e1y - $sy * $e1x;
        $vv = $f * ($dx * $qx + $dy * $qy + $dz * $qz);
        if ($vv < 0.0 || $u + $vv > 1.0) {
            return false;
        }

        $t = $f * ($e2x * $qx + $e2y * $qy + $e2z * $qz);
        return $t > $eps;
    }

    private static function distSq(
        float $ax, float $ay, float $az,
        float $bx, float $by, float $bz
    ): float {
        $dx = $ax - $bx; $dy = $ay - $by; $dz = $az - $bz;
        return $dx * $dx + $dy * $dy + $dz * $dz;
    }
}
