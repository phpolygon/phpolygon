<?php

declare(strict_types=1);

namespace PHPolygon\Build\Mesh;

use PHPolygon\Geometry\MeshData;

/**
 * Linear extrusion of 2D outlines into a closed 3D mesh.
 *
 * Algorithm:
 *   1. Triangulate each outline (XY plane) via ear clipping → top + bottom caps
 *   2. Side faces: one quad per outline edge (XY → XY translated by depth)
 *   3. Combine into a single MeshData
 *
 * Coordinates:
 *   Input outlines are XY (Y-up after the SvgOutlineParser flip).
 *   Output mesh is centered on the origin in Z so it sits on the XY plane.
 *   Depth is the total extrusion thickness; mesh spans Z = -depth/2 .. +depth/2.
 *
 * Triangulation is "ear clipping" without hole support - simple convex or
 * concave polygons work, polygons with interior holes do not. For the v0
 * pipeline this covers the common cases (icons, simple silhouettes).
 *
 * Self-intersecting polygons fall back to fan triangulation, which won't
 * produce a strictly correct mesh but at least won't crash; it's the
 * caller's job to clean up the SVG before importing.
 */
final class MeshExtruder
{
    /**
     * @param list<list<array{0: float, 1: float}>> $outlines
     */
    public function extrude(array $outlines, float $depth = 1.0): MeshData
    {
        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        $halfD = $depth / 2.0;

        foreach ($outlines as $outline) {
            $outline = $this->ensureCcw($this->stripRepeated($outline));
            if (count($outline) < 3) continue;

            // ── Top cap (z = +halfD, normal = +Z) ────────────────────────
            $base = (int)(count($vertices) / 3);
            foreach ($outline as $p) {
                $vertices[] = $p[0]; $vertices[] = $p[1]; $vertices[] = +$halfD;
                $normals[]  = 0.0;   $normals[]  = 0.0;   $normals[]  = 1.0;
                $uvs[]      = $p[0] + 0.5;
                $uvs[]      = $p[1] + 0.5;
            }
            $tris = $this->earClip($outline);
            foreach ($tris as $t) {
                $indices[] = $base + $t[0];
                $indices[] = $base + $t[1];
                $indices[] = $base + $t[2];
            }

            // ── Bottom cap (z = -halfD, normal = -Z) ─────────────────────
            $base = (int)(count($vertices) / 3);
            foreach ($outline as $p) {
                $vertices[] = $p[0]; $vertices[] = $p[1]; $vertices[] = -$halfD;
                $normals[]  = 0.0;   $normals[]  = 0.0;   $normals[]  = -1.0;
                $uvs[]      = $p[0] + 0.5;
                $uvs[]      = $p[1] + 0.5;
            }
            // Flip winding so the bottom cap faces -Z.
            foreach ($tris as $t) {
                $indices[] = $base + $t[0];
                $indices[] = $base + $t[2];
                $indices[] = $base + $t[1];
            }

            // ── Side faces (one quad per outline edge) ──────────────────
            $n = count($outline);
            for ($i = 0; $i < $n; $i++) {
                $a = $outline[$i];
                $b = $outline[($i + 1) % $n];
                $ex = $b[0] - $a[0];
                $ey = $b[1] - $a[1];
                $len = sqrt($ex * $ex + $ey * $ey);
                if ($len < 1e-9) continue;
                // Outward normal (CCW polygon -> rotate edge -90°).
                $nx =  $ey / $len;
                $ny = -$ex / $len;

                $base = (int)(count($vertices) / 3);
                $vertices[] = $a[0]; $vertices[] = $a[1]; $vertices[] = +$halfD;
                $normals[]  = $nx;   $normals[]  = $ny;   $normals[]  = 0.0;
                $uvs[]      = 0.0;   $uvs[]      = 0.0;

                $vertices[] = $a[0]; $vertices[] = $a[1]; $vertices[] = -$halfD;
                $normals[]  = $nx;   $normals[]  = $ny;   $normals[]  = 0.0;
                $uvs[]      = 0.0;   $uvs[]      = 1.0;

                $vertices[] = $b[0]; $vertices[] = $b[1]; $vertices[] = -$halfD;
                $normals[]  = $nx;   $normals[]  = $ny;   $normals[]  = 0.0;
                $uvs[]      = 1.0;   $uvs[]      = 1.0;

                $vertices[] = $b[0]; $vertices[] = $b[1]; $vertices[] = +$halfD;
                $normals[]  = $nx;   $normals[]  = $ny;   $normals[]  = 0.0;
                $uvs[]      = 1.0;   $uvs[]      = 0.0;

                $indices[] = $base + 0; $indices[] = $base + 1; $indices[] = $base + 2;
                $indices[] = $base + 0; $indices[] = $base + 2; $indices[] = $base + 3;
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
     * Drop trailing duplicate of the first point and any zero-length edges.
     *
     * @param list<array{0: float, 1: float}> $polygon
     * @return list<array{0: float, 1: float}>
     */
    private function stripRepeated(array $polygon): array
    {
        $out = [];
        foreach ($polygon as $p) {
            $last = $out === [] ? null : $out[count($out) - 1];
            if ($last === null || abs($p[0] - $last[0]) > 1e-9 || abs($p[1] - $last[1]) > 1e-9) {
                $out[] = $p;
            }
        }
        // Remove trailing close-to-start duplicate.
        if (count($out) >= 2) {
            $first = $out[0];
            $last  = $out[count($out) - 1];
            if (abs($first[0] - $last[0]) < 1e-9 && abs($first[1] - $last[1]) < 1e-9) {
                array_pop($out);
            }
        }
        return $out;
    }

    /**
     * Force counter-clockwise winding (positive signed area). The shoelace
     * formula gives signed area / 2; positive in CCW for Y-up.
     *
     * @param list<array{0: float, 1: float}> $polygon
     * @return list<array{0: float, 1: float}>
     */
    private function ensureCcw(array $polygon): array
    {
        $area = 0.0;
        $n = count($polygon);
        for ($i = 0; $i < $n; $i++) {
            $a = $polygon[$i];
            $b = $polygon[($i + 1) % $n];
            $area += ($b[0] - $a[0]) * ($b[1] + $a[1]);
        }
        // Shoelace returns 2× negative area for CCW; flip if positive.
        return $area > 0.0 ? array_reverse($polygon) : $polygon;
    }

    /**
     * Ear clipping triangulation. Returns triangles as index triples into
     * the original polygon. Falls back to fan triangulation if the polygon
     * is degenerate enough to break the algorithm.
     *
     * @param list<array{0: float, 1: float}> $polygon
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function earClip(array $polygon): array
    {
        $n = count($polygon);
        if ($n < 3) return [];
        if ($n === 3) return [[0, 1, 2]];

        $indices = range(0, $n - 1);
        $tris    = [];
        $guard   = 2 * $n; // safety bound against pathological polygons

        while (count($indices) > 2 && $guard-- > 0) {
            $earFound = false;
            $m = count($indices);
            for ($i = 0; $i < $m; $i++) {
                $iPrev = $indices[($i - 1 + $m) % $m];
                $iCurr = $indices[$i];
                $iNext = $indices[($i + 1) % $m];

                $a = $polygon[$iPrev];
                $b = $polygon[$iCurr];
                $c = $polygon[$iNext];

                if (!$this->isConvex($a, $b, $c)) continue;
                if ($this->anyPointInTriangle($polygon, $indices, $iPrev, $iCurr, $iNext, $a, $b, $c)) continue;

                $tris[] = [$iPrev, $iCurr, $iNext];
                array_splice($indices, $i, 1);
                $earFound = true;
                break;
            }
            if (!$earFound) break; // give up - geometry is broken
        }

        // Fallback: fan triangulation of any remaining polygon.
        if (count($indices) > 2) {
            $tris = [];
            for ($i = 1; $i < $n - 1; $i++) {
                $tris[] = [0, $i, $i + 1];
            }
        }
        return $tris;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     */
    private function isConvex(array $a, array $b, array $c): bool
    {
        // Cross product of (b - a) × (c - b). Positive in CCW polygons
        // means the corner at b is convex.
        $cross = ($b[0] - $a[0]) * ($c[1] - $b[1]) - ($b[1] - $a[1]) * ($c[0] - $b[0]);
        return $cross > 0.0;
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     * @param list<int> $indices
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     */
    private function anyPointInTriangle(
        array $polygon, array $indices,
        int $iPrev, int $iCurr, int $iNext,
        array $a, array $b, array $c,
    ): bool {
        foreach ($indices as $idx) {
            if ($idx === $iPrev || $idx === $iCurr || $idx === $iNext) continue;
            if ($this->pointInTriangle($polygon[$idx], $a, $b, $c)) return true;
        }
        return false;
    }

    /**
     * @param array{0: float, 1: float} $p
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     */
    private function pointInTriangle(array $p, array $a, array $b, array $c): bool
    {
        $d1 = ($p[0] - $b[0]) * ($a[1] - $b[1]) - ($a[0] - $b[0]) * ($p[1] - $b[1]);
        $d2 = ($p[0] - $c[0]) * ($b[1] - $c[1]) - ($b[0] - $c[0]) * ($p[1] - $c[1]);
        $d3 = ($p[0] - $a[0]) * ($c[1] - $a[1]) - ($c[0] - $a[0]) * ($p[1] - $a[1]);
        $hasNeg = ($d1 < 0) || ($d2 < 0) || ($d3 < 0);
        $hasPos = ($d1 > 0) || ($d2 > 0) || ($d3 > 0);
        return !($hasNeg && $hasPos);
    }
}
