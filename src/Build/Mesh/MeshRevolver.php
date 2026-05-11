<?php

declare(strict_types=1);

namespace PHPolygon\Build\Mesh;

use PHPolygon\Geometry\MeshData;

/**
 * Revolution (lathe) of a 2D profile around the Y axis.
 *
 * Input: an open polyline (the profile of a solid as seen from the side).
 * Output: a 3D solid of revolution swept around Y at $segments uniform
 *         angular steps.
 *
 * Use-cases that fit this generator:
 *   - tires, wheels, rims (cross-section profile → full ring)
 *   - bottles, vases, cups, pillars
 *   - any axisymmetric automotive component (exhaust pipes, hubs)
 *
 * Coordinate convention:
 *   The profile is XY (X = distance from axis, Y = height along axis).
 *   X must be ≥ 0; points on the axis (X = 0) collapse the corresponding
 *   ring into a pole. The generated mesh is centered on the Y axis with
 *   the profile spinning to fill it.
 *
 * Profile orientation:
 *   The profile is rendered "as drawn" from the +Z direction. Rotation
 *   sweeps from +X clockwise toward -Z, then -X, then +Z, then back to
 *   +X. Profile order should follow Y top-to-bottom or bottom-to-top
 *   consistently; CCW vs CW only affects the global winding.
 *
 * Caps:
 *   No automatic cap is generated - the caller can either include cap
 *   geometry in the profile (close the profile back to X=0 to form a
 *   solid) or leave the ends open (for a pipe / hollow tube).
 */
final class MeshRevolver
{
    /**
     * @param list<array{0: float, 1: float}> $profile Open or closed
     *   polyline. Each point is (radius, height).
     */
    public function revolve(array $profile, int $segments = 32): MeshData
    {
        $segments = max(3, $segments);
        $n = count($profile);
        if ($n < 2) {
            return new MeshData(vertices: [], normals: [], uvs: [], indices: []);
        }

        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        // ── Generate ring vertices: ($segments + 1) × $n ──────────────────
        // The +1 lets us duplicate the seam vertices with their own UVs
        // (u=0 and u=1) so the wrap-around texture sample is correct.
        for ($s = 0; $s <= $segments; $s++) {
            $theta = 2.0 * M_PI * $s / $segments;
            $cos = cos($theta);
            $sin = sin($theta);
            $u   = (float)$s / $segments;

            for ($i = 0; $i < $n; $i++) {
                [$r, $y] = $profile[$i];
                $x = $r * $cos;
                $z = $r * $sin;
                $v = (float)$i / ($n - 1);

                $vertices[] = $x;
                $vertices[] = $y;
                $vertices[] = $z;

                // Approximate normal from the profile's local edge direction
                // crossed with the tangential direction. Fully smooth
                // shading; for hard creases the caller should split the
                // profile into segments with duplicate points.
                [$nx, $ny, $nz] = $this->normalAt($profile, $i, $cos, $sin);
                $normals[] = $nx;
                $normals[] = $ny;
                $normals[] = $nz;

                $uvs[] = $u;
                $uvs[] = $v;
            }
        }

        // ── Generate quads between adjacent rings ─────────────────────────
        for ($s = 0; $s < $segments; $s++) {
            $rowA = $s       * $n;
            $rowB = ($s + 1) * $n;
            for ($i = 0; $i < $n - 1; $i++) {
                $a = $rowA + $i;
                $b = $rowB + $i;
                $c = $rowB + $i + 1;
                $d = $rowA + $i + 1;
                // CCW from outside (positive radius pointing outward).
                $indices[] = $a; $indices[] = $b; $indices[] = $c;
                $indices[] = $a; $indices[] = $c; $indices[] = $d;
            }
        }

        return new MeshData(
            vertices: $vertices,
            normals:  $normals,
            uvs:      $uvs,
            indices:  $indices,
        );
    }

    /**
     * Smooth normal at profile vertex $i, sampled at angle (cos, sin).
     *
     * The normal in the (radial, vertical) plane is perpendicular to the
     * profile tangent rotated 90° outward. We then rotate that plane
     * normal around the Y axis by the current sweep angle to get the
     * world-space normal.
     *
     * @param list<array{0: float, 1: float}> $profile
     * @return array{0: float, 1: float, 2: float}
     */
    private function normalAt(array $profile, int $i, float $cos, float $sin): array
    {
        $n = count($profile);

        // Tangent: average of incoming and outgoing edge to smooth corners.
        $tx = 0.0; $ty = 0.0; $count = 0;
        if ($i > 0) {
            $tx += $profile[$i][0] - $profile[$i - 1][0];
            $ty += $profile[$i][1] - $profile[$i - 1][1];
            $count++;
        }
        if ($i < $n - 1) {
            $tx += $profile[$i + 1][0] - $profile[$i][0];
            $ty += $profile[$i + 1][1] - $profile[$i][1];
            $count++;
        }
        if ($count === 0) {
            return [0.0, 1.0, 0.0]; // degenerate
        }

        // Normal in the radial-vertical plane: rotate tangent -90°
        // (so it points radially outward when the profile is drawn CCW
        // around the axis from +X looking down).
        $nr =  $ty;
        $ny = -$tx;
        $len = sqrt($nr * $nr + $ny * $ny);
        if ($len < 1e-9) {
            return [0.0, 1.0, 0.0];
        }
        $nr /= $len;
        $ny /= $len;

        // Rotate the radial component around Y by (cos, sin) to get the
        // world-space horizontal direction.
        $nx = $nr * $cos;
        $nz = $nr * $sin;
        return [$nx, $ny, $nz];
    }
}
