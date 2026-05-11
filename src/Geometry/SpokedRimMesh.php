<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Procedural alloy rim with radial spokes.
 *
 * Geometry breakdown (centered at the origin, axis = +Y, matching the
 * vehicle wheel convention where the wheel sits on the X axis after a
 * 90° rotation):
 *
 *   - outer ring:   thin cylinder shell at $outerRadius (chrome lip)
 *   - inner ring:   slightly smaller cylinder shell at $innerRadius (hub)
 *   - hub disc:     small cylinder cap at the centre (lug-bolt area)
 *   - spokes:       $spokeCount thin radial bars connecting the hub to
 *                   the outer ring; each bar is a short box rotated to
 *                   point outward
 *
 * The result reads as a simple 5-/6-spoke alloy when paired with the
 * default chrome material, with no per-face artwork required - all
 * shape variation comes from the geometry alone.
 *
 * Coordinate convention (post-rotation by Car prefab):
 *   axis = +Y (cylinder height = wheel width)
 *   $outerRadius matches the tire's inner edge, so the rim sits flush
 *   inside the tire when both meshes share a transform.
 *
 * Render cost: ~12 (outer) + 12 (inner) + 12 (hub) + 6 × spokes ≈ 60-80
 * triangles per rim. Cheap enough that all four wheels combined stay
 * well under 400 triangles.
 */
final class SpokedRimMesh
{
    public static function generate(
        float $outerRadius,
        float $innerRadius,
        float $width,
        int   $spokeCount = 5,
        float $spokeWidth = 0.05,
        float $spokeDepth = 0.04,
        int   $segments = 16,
    ): MeshData {
        return PerfProfiler::section('mesh.generate.spoked_rim', static fn(): MeshData
            => self::generateImpl(
                $outerRadius, $innerRadius, $width,
                $spokeCount, $spokeWidth, $spokeDepth, $segments,
            ));
    }

    private static function generateImpl(
        float $outerRadius,
        float $innerRadius,
        float $width,
        int   $spokeCount,
        float $spokeWidth,
        float $spokeDepth,
        int   $segments,
    ): MeshData {
        // Sanity clamps so the rim never inverts when callers pass exotic
        // values (Compact wheels are very small).
        $innerRadius = max(0.05, min($innerRadius, $outerRadius - 0.02));
        $hubRadius   = max(0.04, $innerRadius * 0.45);
        $spokeCount  = max(3, min($spokeCount, 12));

        $halfW = $width / 2.0;

        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        // ── Outer chrome lip (visible side ring) ─────────────────────────
        self::appendRingShell(
            $vertices, $normals, $uvs, $indices,
            $outerRadius, $halfW, $segments, /* outwardNormal */ true,
        );

        // ── Inner ring (hub area, slightly inset on the +Y face) ─────────
        // Slightly thinner than the outer band so the chrome face plate
        // reads as recessed.
        $hubFaceY = $halfW * 0.55;
        self::appendRingShell(
            $vertices, $normals, $uvs, $indices,
            $innerRadius, $hubFaceY, $segments, /* outwardNormal */ true,
        );

        // ── Front disc cap covering the hub area ─────────────────────────
        // This is the visible "face" of the rim that the spokes sit on.
        // Built as a triangle fan with the centre at +halfW so it sits
        // outboard of the wheel - matches the Car prefab's "rim slightly
        // wider than tire" rule.
        self::appendDiscFan(
            $vertices, $normals, $uvs, $indices,
            $innerRadius, $halfW, $segments, /* normalSign */ +1.0,
        );

        // ── Hub centre boss (lug-bolt area) ──────────────────────────────
        self::appendDiscFan(
            $vertices, $normals, $uvs, $indices,
            $hubRadius, $halfW + 0.01, $segments, /* normalSign */ +1.0,
        );

        // ── Radial spokes ────────────────────────────────────────────────
        // Each spoke is an axis-aligned box, centred along its radial axis
        // and then rotated by spokeAngle around Y. The box spans from
        // $hubRadius to $innerRadius along the radial direction.
        $spokeLength = $innerRadius - $hubRadius;
        if ($spokeLength > 0.02) {
            for ($s = 0; $s < $spokeCount; $s++) {
                $angle = (2.0 * M_PI * $s) / $spokeCount;
                self::appendSpoke(
                    $vertices, $normals, $uvs, $indices,
                    $hubRadius, $innerRadius,
                    $spokeWidth, $spokeDepth, $halfW,
                    $angle,
                );
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
     * Append a short cylinder shell (no caps) of the given radius, height
     * 2*$halfW, centred on the origin, with normals pointing outward
     * ($outward=true) or inward.
     *
     * @param float[] $vertices
     * @param float[] $normals
     * @param float[] $uvs
     * @param int[]   $indices
     */
    private static function appendRingShell(
        array &$vertices, array &$normals, array &$uvs, array &$indices,
        float $radius, float $halfW, int $segments, bool $outward,
    ): void {
        $base = (int)(count($vertices) / 3);
        $sign = $outward ? 1.0 : -1.0;

        for ($i = 0; $i <= $segments; $i++) {
            $theta = 2.0 * M_PI * $i / $segments;
            $cos = cos($theta);
            $sin = sin($theta);
            $u   = (float)$i / $segments;

            // bottom (-Y)
            $vertices[] = $radius * $cos;
            $vertices[] = -$halfW;
            $vertices[] = $radius * $sin;
            $normals[]  = $sign * $cos; $normals[] = 0.0; $normals[] = $sign * $sin;
            $uvs[]      = $u; $uvs[] = 0.0;

            // top (+Y)
            $vertices[] = $radius * $cos;
            $vertices[] = +$halfW;
            $vertices[] = $radius * $sin;
            $normals[]  = $sign * $cos; $normals[] = 0.0; $normals[] = $sign * $sin;
            $uvs[]      = $u; $uvs[] = 1.0;
        }

        for ($i = 0; $i < $segments; $i++) {
            $a = $base + $i * 2;
            $b = $base + $i * 2 + 1;
            $c = $base + ($i + 1) * 2;
            $d = $base + ($i + 1) * 2 + 1;
            if ($outward) {
                $indices[] = $a; $indices[] = $b; $indices[] = $d;
                $indices[] = $a; $indices[] = $d; $indices[] = $c;
            } else {
                $indices[] = $a; $indices[] = $d; $indices[] = $b;
                $indices[] = $a; $indices[] = $c; $indices[] = $d;
            }
        }
    }

    /**
     * Append a flat disc (triangle fan) at $y = const, normal = (0, sign, 0).
     *
     * @param float[] $vertices
     * @param float[] $normals
     * @param float[] $uvs
     * @param int[]   $indices
     */
    private static function appendDiscFan(
        array &$vertices, array &$normals, array &$uvs, array &$indices,
        float $radius, float $y, int $segments, float $normalSign,
    ): void {
        $base = (int)(count($vertices) / 3);

        // Centre vertex
        $vertices[] = 0.0; $vertices[] = $y; $vertices[] = 0.0;
        $normals[]  = 0.0; $normals[]  = $normalSign; $normals[]  = 0.0;
        $uvs[]      = 0.5; $uvs[]      = 0.5;

        for ($i = 0; $i <= $segments; $i++) {
            $theta = 2.0 * M_PI * $i / $segments;
            $cos = cos($theta);
            $sin = sin($theta);

            $vertices[] = $radius * $cos;
            $vertices[] = $y;
            $vertices[] = $radius * $sin;
            $normals[]  = 0.0; $normals[] = $normalSign; $normals[] = 0.0;
            $uvs[]      = 0.5 + 0.5 * $cos;
            $uvs[]      = 0.5 + 0.5 * $sin;
        }

        for ($i = 0; $i < $segments; $i++) {
            $a = $base;
            $b = $base + 1 + $i;
            $c = $base + 1 + $i + 1;
            if ($normalSign > 0.0) {
                $indices[] = $a; $indices[] = $c; $indices[] = $b;
            } else {
                $indices[] = $a; $indices[] = $b; $indices[] = $c;
            }
        }
    }

    /**
     * Append a single radial spoke: a box of dimension
     * (length × spokeWidth × spokeDepth) centred at the midpoint between
     * $r0 and $r1 along the radial direction, rotated by $angle around Y.
     *
     * The spoke's "length" is along its local +X (after rotation, this
     * points radially outward from the hub). "Width" is along Y (wheel
     * axis). "Depth" is along the local Z (tangential, gives the spoke
     * a visible side face).
     *
     * @param float[] $vertices
     * @param float[] $normals
     * @param float[] $uvs
     * @param int[]   $indices
     */
    private static function appendSpoke(
        array &$vertices, array &$normals, array &$uvs, array &$indices,
        float $r0, float $r1, float $width, float $depth, float $halfW,
        float $angle,
    ): void {
        $base = (int)(count($vertices) / 3);

        $cos = cos($angle);
        $sin = sin($angle);

        $halfD = $depth / 2.0;
        // Outboard face of the spoke sits just inside the chrome face plate.
        $yTop = $halfW * 0.92;
        $yBot = $halfW * 0.50;

        // Eight corners of the box, listed for the two pairs (inner / outer
        // along radius, four around the cross-section).
        $corners = [
            [$r0, $yBot, -$halfD], [$r0, $yTop, -$halfD],
            [$r0, $yTop, +$halfD], [$r0, $yBot, +$halfD],
            [$r1, $yBot, -$halfD], [$r1, $yTop, -$halfD],
            [$r1, $yTop, +$halfD], [$r1, $yBot, +$halfD],
        ];

        // Rotate each corner around Y by $angle.
        foreach ($corners as $c) {
            $x = $c[0] * $cos - $c[2] * $sin;
            $z = $c[0] * $sin + $c[2] * $cos;
            $vertices[] = $x;
            $vertices[] = $c[1];
            $vertices[] = $z;
            // Normal placeholder; finalised per-face below via duplication
            // would inflate vertex count by 8, so we use a smooth average:
            // the spoke is small enough that flat-shading isn't critical.
            $normals[]  = 0.0; $normals[]  = 1.0; $normals[]  = 0.0;
            $uvs[]      = 0.0; $uvs[]      = 0.0;
        }

        // 12 triangles, 6 quad faces of the box.
        // Indices into $corners: 0..3 inner ring, 4..7 outer ring (tangent
        // order: -Y/-Z -> +Y/-Z -> +Y/+Z -> -Y/+Z).
        $f = static function (int $a, int $b, int $c) use (&$indices, $base): void {
            $indices[] = $base + $a;
            $indices[] = $base + $b;
            $indices[] = $base + $c;
        };

        // Top (+Y face)
        $f(1, 5, 6); $f(1, 6, 2);
        // Bottom (-Y face)
        $f(0, 3, 7); $f(0, 7, 4);
        // -Z face
        $f(0, 4, 5); $f(0, 5, 1);
        // +Z face
        $f(2, 6, 7); $f(2, 7, 3);
        // Inner cap (toward hub)
        $f(0, 1, 2); $f(0, 2, 3);
        // Outer cap (toward rim)
        $f(4, 7, 6); $f(4, 6, 5);
    }
}
