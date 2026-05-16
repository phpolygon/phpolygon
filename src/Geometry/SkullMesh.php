<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Procedural humanoid skull mesh. Built on top of a UV sphere then
 * displaced radially per vertex so that the silhouette picks up
 * anatomical features:
 *
 *  - eye sockets        : two roughly circular depressions on the
 *                         upper front of the skull, large enough to
 *                         seat a sclera sphere of comparable size
 *
 * Future versions extend this with forehead/brow ridge, cheek bones,
 * and chin protrusion. The current generator owns the eye-socket
 * feature alone so it can ship without a complete anatomical pass.
 *
 * Conventions (consistent with SphereMesh):
 *   +Y is up, +Z is forward (face direction), +X is the character's
 *   left side (mirrored relative to the viewer when the character
 *   looks toward +Z and the camera is at +Z).
 *
 * The displacement is computed in unit-direction space, then applied
 * to the radius-scaled vertex. Per-vertex normals are recomputed from
 * face normals because the displaced surface no longer matches the
 * pristine sphere normal.
 */
class SkullMesh
{
    /**
     * Centre of the eye-socket depression on a unit sphere
     * (length = 1). The depression is mirrored across X.
     */
    private const float EYE_CENTER_X = 0.55;
    private const float EYE_CENTER_Y = 0.10;

    /** Angular radius (in unit-direction space) of the depression. */
    private const float EYE_RADIUS   = 0.32;

    /** Maximum radial displacement at the deepest point (fraction of radius). */
    private const float EYE_DEPTH    = 0.12;

    public static function generate(float $radius = 0.5, int $stacks = 24, int $slices = 36): MeshData
    {
        return PerfProfiler::section('mesh.generate.skull', static fn(): MeshData
            => self::generateImpl($radius, $stacks, $slices));
    }

    private static function generateImpl(float $radius, int $stacks, int $slices): MeshData
    {
        $stacks = max(8, $stacks);
        $slices = max(8, $slices);

        $vertices = [];
        $uvs      = [];

        for ($i = 0; $i <= $stacks; $i++) {
            $phi    = M_PI * $i / $stacks;
            $sinPhi = sin($phi);
            $cosPhi = cos($phi);

            for ($j = 0; $j <= $slices; $j++) {
                $theta    = 2.0 * M_PI * $j / $slices;
                $sinTheta = sin($theta);
                $cosTheta = cos($theta);

                $nx = $cosTheta * $sinPhi;
                $ny = $cosPhi;
                $nz = $sinTheta * $sinPhi;

                $displacement = self::skullDisplacement($nx, $ny, $nz);
                $r = $radius * (1.0 + $displacement);

                $vertices[] = $r * $nx;
                $vertices[] = $r * $ny;
                $vertices[] = $r * $nz;

                $uvs[] = (float) $j / $slices;
                $uvs[] = (float) $i / $stacks;
            }
        }

        $indices = [];
        for ($i = 0; $i < $stacks; $i++) {
            for ($j = 0; $j < $slices; $j++) {
                $a = $i * ($slices + 1) + $j;
                $b = $a + ($slices + 1);

                $indices[] = $a;
                $indices[] = $b;
                $indices[] = $a + 1;
                $indices[] = $b;
                $indices[] = $b + 1;
                $indices[] = $a + 1;
            }
        }

        $normals = self::recomputeNormals($vertices, $indices);

        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    /**
     * Per-vertex radial displacement in unit-direction space. Returns the
     * fraction by which the radius shrinks (negative) or grows (positive)
     * at the given direction.
     */
    private static function skullDisplacement(float $nx, float $ny, float $nz): float
    {
        $d = 0.0;

        // Eye sockets - mirrored about X. Only carved on the front hemisphere.
        if ($nz > 0.15) {
            foreach ([-self::EYE_CENTER_X, self::EYE_CENTER_X] as $ecx) {
                $dx   = $nx - $ecx;
                $dy   = $ny - self::EYE_CENTER_Y;
                $dist = sqrt($dx * $dx + $dy * $dy);
                if ($dist >= self::EYE_RADIUS) {
                    continue;
                }
                // Smooth cosine fall-off: 1 at centre, 0 at the rim.
                $t = 1.0 - $dist / self::EYE_RADIUS;
                $falloff = $t * $t * (3.0 - 2.0 * $t);
                // Front-facing damping - the depression should not bleed onto
                // the temples.
                $frontWeight = self::smoothstep01(($nz - 0.15) / 0.55);
                $d -= self::EYE_DEPTH * $falloff * $frontWeight;
            }
        }

        return $d;
    }

    /**
     * Accumulate face normals per vertex and renormalise. The mesh is closed
     * (sphere topology), so every vertex is shared by multiple triangles and
     * the averaged normal gives smooth shading.
     *
     * UV-sphere pole vertices fall on top of each other (slices+1 verts at
     * the same xyz with different uvs). The triangles touching the very
     * first / last vertex of each pole row are fully degenerate (zero
     * area), so accumulation leaves those vertices with a null normal.
     * For any vertex that ends up with a zero-length accumulator we fall
     * back to the unit direction of the vertex itself.
     *
     * @param  float[] $vertices
     * @param  int[]   $indices
     * @return list<float>
     */
    private static function recomputeNormals(array $vertices, array $indices): array
    {
        $vertexCount = (int) (count($vertices) / 3);
        $normals = array_fill(0, $vertexCount * 3, 0.0);

        $triCount = (int) (count($indices) / 3);
        for ($t = 0; $t < $triCount; $t++) {
            $a = $indices[$t * 3];
            $b = $indices[$t * 3 + 1];
            $c = $indices[$t * 3 + 2];

            $ax = $vertices[$a * 3];     $ay = $vertices[$a * 3 + 1]; $az = $vertices[$a * 3 + 2];
            $bx = $vertices[$b * 3];     $by = $vertices[$b * 3 + 1]; $bz = $vertices[$b * 3 + 2];
            $cx = $vertices[$c * 3];     $cy = $vertices[$c * 3 + 1]; $cz = $vertices[$c * 3 + 2];

            $e1x = $bx - $ax; $e1y = $by - $ay; $e1z = $bz - $az;
            $e2x = $cx - $ax; $e2y = $cy - $ay; $e2z = $cz - $az;

            $nx = $e1y * $e2z - $e1z * $e2y;
            $ny = $e1z * $e2x - $e1x * $e2z;
            $nz = $e1x * $e2y - $e1y * $e2x;

            $normals[$a * 3]     += $nx; $normals[$a * 3 + 1] += $ny; $normals[$a * 3 + 2] += $nz;
            $normals[$b * 3]     += $nx; $normals[$b * 3 + 1] += $ny; $normals[$b * 3 + 2] += $nz;
            $normals[$c * 3]     += $nx; $normals[$c * 3 + 1] += $ny; $normals[$c * 3 + 2] += $nz;
        }

        for ($k = 0; $k < $vertexCount; $k++) {
            $i = $k * 3;
            $len = sqrt(
                $normals[$i] * $normals[$i]
                + $normals[$i + 1] * $normals[$i + 1]
                + $normals[$i + 2] * $normals[$i + 2]
            );
            if ($len > 1e-9) {
                $normals[$i]     /= $len;
                $normals[$i + 1] /= $len;
                $normals[$i + 2] /= $len;
                continue;
            }
            // Fallback for degenerate-triangle vertices (pole seam).
            $vx = $vertices[$i];
            $vy = $vertices[$i + 1];
            $vz = $vertices[$i + 2];
            $vLen = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
            if ($vLen > 1e-9) {
                $normals[$i]     = $vx / $vLen;
                $normals[$i + 1] = $vy / $vLen;
                $normals[$i + 2] = $vz / $vLen;
            } else {
                $normals[$i]     = 0.0;
                $normals[$i + 1] = 1.0;
                $normals[$i + 2] = 0.0;
            }
        }

        return array_values($normals);
    }

    private static function smoothstep01(float $t): float
    {
        if ($t <= 0.0) {
            return 0.0;
        }
        if ($t >= 1.0) {
            return 1.0;
        }
        return $t * $t * (3.0 - 2.0 * $t);
    }
}
