<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec2;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Solid of revolution: rotate a 2D profile around the Y axis.
 *
 * The profile is a polyline of Vec2 points where x = radius (must be >= 0)
 * and y = height. Rotation sweeps through $segments uniform angular steps.
 *
 * No automatic caps. Close the profile by terminating at x=0 (touches the
 * axis -> forms a pole) for solids, or leave it open for tubes / pipes.
 * For a hollow object (cup, vase) the profile should trace outside-up,
 * across the rim, and inside-down back to the bottom.
 *
 * Smooth shading by default. For a hard crease, duplicate the profile
 * point at that location.
 */
class LatheMesh
{
    /**
     * @param Vec2[] $profile  At least 2 points. Each point's x must be >= 0.
     */
    public static function generate(array $profile, int $segments = 32): MeshData
    {
        return PerfProfiler::section('mesh.generate.lathe', static fn(): MeshData
            => self::generateImpl($profile, $segments));
    }

    /**
     * @param Vec2[] $profile
     */
    private static function generateImpl(array $profile, int $segments): MeshData
    {
        $segments = max(3, $segments);
        $n = count($profile);
        if ($n < 2) {
            return new MeshData(vertices: [], normals: [], uvs: [], indices: []);
        }

        // Profile-plane normals (smooth across adjacent segments).
        $profileNormals = [];
        for ($i = 0; $i < $n; $i++) {
            $tx = 0.0;
            $ty = 0.0;
            if ($i > 0) {
                $tx += $profile[$i]->x - $profile[$i - 1]->x;
                $ty += $profile[$i]->y - $profile[$i - 1]->y;
            }
            if ($i < $n - 1) {
                $tx += $profile[$i + 1]->x - $profile[$i]->x;
                $ty += $profile[$i + 1]->y - $profile[$i]->y;
            }
            // Outward normal = tangent rotated -90 degrees in (radius, height)
            // plane. For a profile drawn bottom-up with radius increasing,
            // this points away from the Y axis.
            $nr =  $ty;
            $ny = -$tx;
            $len = sqrt($nr * $nr + $ny * $ny);
            if ($len < 1e-9) {
                $profileNormals[$i] = [0.0, 1.0];
                continue;
            }
            $profileNormals[$i] = [$nr / $len, $ny / $len];
        }

        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        // Ring vertices: ($segments + 1) x $n. The seam is duplicated so
        // u=0 and u=1 each get their own vertex (correct UV wrap).
        for ($s = 0; $s <= $segments; $s++) {
            $theta = 2.0 * M_PI * $s / $segments;
            $cos = cos($theta);
            $sin = sin($theta);
            $u   = (float)$s / $segments;

            for ($i = 0; $i < $n; $i++) {
                $r = $profile[$i]->x;
                $y = $profile[$i]->y;

                $vertices[] = $r * $cos;
                $vertices[] = $y;
                $vertices[] = $r * $sin;

                [$nr, $ny] = $profileNormals[$i];
                $normals[] = $nr * $cos;
                $normals[] = $ny;
                $normals[] = $nr * $sin;

                $uvs[] = $u;
                $uvs[] = (float)$i / max(1, $n - 1);
            }
        }

        // Quads between adjacent rings.
        for ($s = 0; $s < $segments; $s++) {
            $rowA = $s       * $n;
            $rowB = ($s + 1) * $n;
            for ($i = 0; $i < $n - 1; $i++) {
                $a = $rowA + $i;
                $b = $rowB + $i;
                $c = $rowB + $i + 1;
                $d = $rowA + $i + 1;
                $indices[] = $a; $indices[] = $b; $indices[] = $c;
                $indices[] = $a; $indices[] = $c; $indices[] = $d;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
