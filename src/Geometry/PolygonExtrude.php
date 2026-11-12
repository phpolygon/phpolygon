<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec2;

/**
 * Extrude a 2D polygon (optionally with hole polygons) into a prism.
 *
 * The polygon lies in the XY plane and is extruded along +Z from z = 0 to
 * z = $depth. The front cap (z = $depth) faces +Z, the back cap faces -Z.
 * Outline and holes may be given in either winding; they are normalised
 * (outline CCW, holes CW) so all normals point out of the solid. Side faces
 * (outer walls and the inner walls of holes, the "reveals") are flat-shaded
 * quads with one vertex pair per edge.
 *
 * UVs are in world units (metres, times $uvScale): caps use (x, y); sides use
 * (distance along the ring, z). Tangents are computed from the UVs.
 *
 * Caps are triangulated by ear clipping after bridging each hole to the outer
 * ring. Input must be simple (no self-intersections), holes must lie inside
 * the outline and must not touch each other. Collinear input vertices are
 * allowed but may leave T-junctions in the caps (the side walls keep them).
 *
 * `$axis` ('x'|'y'|'z', default 'z') picks the extrusion axis: the prism is built as
 * described above and then rotated by the cyclic permutation that takes Z onto that
 * axis, so the outline's (x, y) becomes (y, z) for 'x' and (z, x) for 'y'; winding
 * and normals stay outward. Caps keep their meaning (front = +axis end, z = depth).
 *
 * Pure function: no static state, safe inside a worker.
 */
final class PolygonExtrude
{
    /**
     * @param list<Vec2>       $outline Outer ring, >= 3 points, first point not repeated.
     * @param list<list<Vec2>> $holes   Hole rings, each >= 3 points.
     */
    public static function generate(
        array $outline,
        float $depth,
        array $holes = [],
        bool $capFront = true,
        bool $capBack = true,
        float $uvScale = 1.0,
        string $axis = 'z',
    ): MeshData {
        if ($axis !== 'z') {
            // Build along +Z, then rotate (see MeshData::axisRotated): 'x' maps the outline's
            // (x, y) to world (y, z) and extrudes along +X; 'y' maps it to (z, x), along +Y.
            return self::generate($outline, $depth, $holes, $capFront, $capBack, $uvScale)
                ->axisRotated(2, MeshData::axisIndex($axis));
        }
        if (count($outline) < 3) {
            return new MeshData([], [], [], []);
        }
        $rings = [self::orient($outline, true)];
        foreach ($holes as $hole) {
            if (count($hole) >= 3) {
                $rings[] = self::orient($hole, false);
            }
        }

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        // Side walls.
        foreach ($rings as $ring) {
            $n = count($ring);
            $run = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $a = $ring[$i];
                $b = $ring[($i + 1) % $n];
                $dx = $b->x - $a->x;
                $dy = $b->y - $a->y;
                $len = sqrt($dx * $dx + $dy * $dy);
                if ($len < 1e-12) {
                    continue;
                }
                $nx = $dy / $len;
                $ny = -$dx / $len;
                $base = intdiv(count($vertices), 3);
                $u0 = $run * $uvScale;
                $u1 = ($run + $len) * $uvScale;
                $v1 = $depth * $uvScale;
                array_push(
                    $vertices,
                    $a->x, $a->y, 0.0,
                    $b->x, $b->y, 0.0,
                    $b->x, $b->y, $depth,
                    $a->x, $a->y, $depth,
                );
                array_push($normals, $nx, $ny, 0.0, $nx, $ny, 0.0, $nx, $ny, 0.0, $nx, $ny, 0.0);
                array_push($uvs, $u0, 0.0, $u1, 0.0, $u1, $v1, $u0, $v1);
                array_push($indices, $base, $base + 1, $base + 2, $base, $base + 2, $base + 3);
                $run += $len;
            }
        }

        if ($capFront || $capBack) {
            $points = [];
            foreach ($rings as $ring) {
                foreach ($ring as $p) {
                    $points[] = [$p->x, $p->y];
                }
            }
            $tris = self::triangulate($points, $rings);
            foreach ([[true, $depth, 1.0], [false, 0.0, -1.0]] as [$front, $z, $nz]) {
                if (($front && !$capFront) || (!$front && !$capBack)) {
                    continue;
                }
                $base = intdiv(count($vertices), 3);
                foreach ($points as [$px, $py]) {
                    array_push($vertices, $px, $py, $z);
                    array_push($normals, 0.0, 0.0, $nz);
                    array_push($uvs, $px * $uvScale, $py * $uvScale);
                }
                foreach ($tris as [$a, $b, $c]) {
                    if ($front) {
                        array_push($indices, $base + $a, $base + $b, $base + $c);
                    } else {
                        array_push($indices, $base + $a, $base + $c, $base + $b);
                    }
                }
            }
        }

        return (new MeshData($vertices, $normals, $uvs, $indices))->withComputedTangents();
    }

    /**
     * Triangulate the flat region described by the rings (outer first, holes
     * after), in the XY plane. Returns triangles as indices into the
     * concatenated ring points, CCW.
     *
     * @param list<list<Vec2>> $rings Already oriented (outer CCW, holes CW).
     * @param list<array{float, float}> $points Concatenated ring points.
     * @return list<array{int, int, int}>
     */
    private static function triangulate(array $points, array $rings): array
    {
        $offset = 0;
        $ids = [];
        foreach ($rings as $ring) {
            $ids[] = range($offset, $offset + count($ring) - 1);
            $offset += count($ring);
        }
        $poly = $ids[0];

        // Merge holes, rightmost first.
        $holes = array_slice($ids, 1);
        usort($holes, static function (array $a, array $b) use ($points): int {
            $ma = self::rightmost($a, $points);
            $mb = self::rightmost($b, $points);
            return $points[$mb][0] <=> $points[$ma][0] ?: $ma <=> $mb;
        });
        foreach ($holes as $hole) {
            $poly = self::bridge($poly, $hole, $points);
        }

        return self::earClip($poly, $points);
    }

    /**
     * @param list<int> $ring
     * @param list<array{float, float}> $points
     */
    private static function rightmost(array $ring, array $points): int
    {
        $best = $ring[0];
        foreach ($ring as $id) {
            if ($points[$id][0] > $points[$best][0]
                || ($points[$id][0] === $points[$best][0] && $points[$id][1] < $points[$best][1])) {
                $best = $id;
            }
        }
        return $best;
    }

    /**
     * Connect a hole to the polygon with a zero-width bridge (Eberly).
     *
     * @param list<int> $poly
     * @param list<int> $hole
     * @param list<array{float, float}> $points
     * @return list<int>
     */
    private static function bridge(array $poly, array $hole, array $points): array
    {
        $m = self::rightmost($hole, $points);
        [$mx, $my] = $points[$m];

        // Ray from M along +X: nearest crossing with the polygon edges.
        $bestX = INF;
        $edgeIdx = -1;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $points[$poly[$i]];
            [$bx, $by] = $points[$poly[($i + 1) % $n]];
            if (($ay > $my && $by > $my) || ($ay < $my && $by < $my) || $ay === $by) {
                continue;
            }
            $t = ($my - $ay) / ($by - $ay);
            $x = $ax + $t * ($bx - $ax);
            if ($x >= $mx - 1e-12 && $x < $bestX) {
                $bestX = $x;
                $edgeIdx = $i;
            }
        }
        if ($edgeIdx < 0) {
            // Hole outside the outline: attach to the nearest polygon vertex.
            $edgeIdx = 0;
            $bestD = INF;
            for ($i = 0; $i < $n; $i++) {
                $d = ($points[$poly[$i]][0] - $mx) ** 2 + ($points[$poly[$i]][1] - $my) ** 2;
                if ($d < $bestD) {
                    $bestD = $d;
                    $edgeIdx = $i;
                }
            }
            $pIdx = $edgeIdx;
        } else {
            $i1 = ($edgeIdx + 1) % $n;
            $pIdx = $points[$poly[$edgeIdx]][0] > $points[$poly[$i1]][0] ? $edgeIdx : $i1;
            [$px, $py] = $points[$poly[$pIdx]];
            // Any vertex inside triangle (M, I, P) blocks the view; take the one
            // with the smallest angle to the ray, nearest on ties.
            $ix = $bestX;
            $iy = $my;
            $bestCos = -2.0;
            $bestDist = INF;
            $chosen = $pIdx;
            if (abs($ix - $px) > 1e-12 || abs($iy - $py) > 1e-12) {
                for ($i = 0; $i < $n; $i++) {
                    if ($i === $pIdx) {
                        continue;
                    }
                    [$vx, $vy] = $points[$poly[$i]];
                    if ($vx < $mx - 1e-12 || !self::inTriangle($vx, $vy, $mx, $my, $ix, $iy, $px, $py)) {
                        continue;
                    }
                    // A bridged vertex occurs twice in the ring; only the occurrence whose
                    // corner opens towards M may take the bridge.
                    if (!self::locallyInside($points, $poly[($i + $n - 1) % $n], $poly[$i], $poly[($i + 1) % $n], $mx, $my)) {
                        continue;
                    }
                    $dx = $vx - $mx;
                    $dy = $vy - $my;
                    $dist = $dx * $dx + $dy * $dy;
                    if ($dist < 1e-24) {
                        continue;
                    }
                    $cos = $dx / sqrt($dist);
                    if ($cos > $bestCos + 1e-12 || (abs($cos - $bestCos) <= 1e-12 && $dist < $bestDist)) {
                        $bestCos = $cos;
                        $bestDist = $dist;
                        $chosen = $i;
                    }
                }
            }
            $pIdx = $chosen;
        }

        // Splice: ... V, M, hole (full turn back to M), M, V ...
        $hn = count($hole);
        $start = array_search($m, $hole, true);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $poly[$i];
            if ($i === $pIdx) {
                for ($k = 0; $k <= $hn; $k++) {
                    $out[] = $hole[((int) $start + $k) % $hn];
                }
                $out[] = $poly[$i];
            }
        }
        return $out;
    }

    /**
     * Whether the direction from vertex $v to (tx, ty) points into the interior
     * of the CCW ring at the corner prev -> v -> next.
     *
     * @param list<array{float, float}> $points
     */
    private static function locallyInside(array $points, int $prev, int $v, int $next, float $tx, float $ty): bool
    {
        [$vx, $vy] = $points[$v];
        $pdx = $points[$prev][0] - $vx;
        $pdy = $points[$prev][1] - $vy;
        $ndx = $points[$next][0] - $vx;
        $ndy = $points[$next][1] - $vy;
        $tdx = $tx - $vx;
        $tdy = $ty - $vy;
        $convex = (-$pdx * ($ndy) + $pdy * $ndx) >= 0.0; // cross(V - P, N - V) >= 0
        if ($convex) {
            return ($ndx * $tdy - $ndy * $tdx) > 0.0 && ($tdx * $pdy - $tdy * $pdx) > 0.0;
        }
        $exterior = ($pdx * $tdy - $pdy * $tdx) > 0.0 && ($tdx * $ndy - $tdy * $ndx) > 0.0;
        return !$exterior;
    }

    private static function inTriangle(float $px, float $py, float $ax, float $ay, float $bx, float $by, float $cx, float $cy): bool
    {
        $d1 = ($px - $bx) * ($ay - $by) - ($ax - $bx) * ($py - $by);
        $d2 = ($px - $cx) * ($by - $cy) - ($bx - $cx) * ($py - $cy);
        $d3 = ($px - $ax) * ($cy - $ay) - ($cx - $ax) * ($py - $ay);
        $neg = $d1 < -1e-12 || $d2 < -1e-12 || $d3 < -1e-12;
        $pos = $d1 > 1e-12 || $d2 > 1e-12 || $d3 > 1e-12;
        return !($neg && $pos);
    }

    /**
     * Ear clipping over a (weakly simple) CCW id ring.
     *
     * @param list<int> $ring
     * @param list<array{float, float}> $points
     * @return list<array{int, int, int}>
     */
    private static function earClip(array $ring, array $points): array
    {
        $tris = [];
        $guard = count($ring) * count($ring) + 16;
        while (count($ring) > 3 && $guard-- > 0) {
            $n = count($ring);
            $clip = -1;
            $degenerate = -1;
            $firstConvex = -1;
            for ($i = 0; $i < $n; $i++) {
                $ia = $ring[($i + $n - 1) % $n];
                $ib = $ring[$i];
                $ic = $ring[($i + 1) % $n];
                [$ax, $ay] = $points[$ia];
                [$bx, $by] = $points[$ib];
                [$cx, $cy] = $points[$ic];
                $cross = ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax);
                $scale = max(abs($bx - $ax) + abs($by - $ay), 1e-12) * max(abs($cx - $ax) + abs($cy - $ay), 1e-12);
                if (abs($cross) <= 1e-12 * $scale) {
                    if ($degenerate < 0) {
                        $degenerate = $i;
                    }
                    continue;
                }
                if ($cross < 0.0) {
                    continue;
                }
                if ($firstConvex < 0) {
                    $firstConvex = $i;
                }
                $blocked = false;
                for ($k = 0; $k < $n; $k++) {
                    $id = $ring[$k];
                    if ($id === $ia || $id === $ib || $id === $ic) {
                        continue;
                    }
                    [$vx, $vy] = $points[$id];
                    if (($vx === $ax && $vy === $ay) || ($vx === $bx && $vy === $by) || ($vx === $cx && $vy === $cy)) {
                        continue;
                    }
                    if (self::inTriangle($vx, $vy, $ax, $ay, $bx, $by, $cx, $cy)) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    $clip = $i;
                    break;
                }
            }
            if ($clip >= 0) {
                $tris[] = [$ring[($clip + $n - 1) % $n], $ring[$clip], $ring[($clip + 1) % $n]];
                array_splice($ring, $clip, 1);
            } elseif ($degenerate >= 0) {
                array_splice($ring, $degenerate, 1);
            } elseif ($firstConvex >= 0) {
                $tris[] = [$ring[($firstConvex + $n - 1) % $n], $ring[$firstConvex], $ring[($firstConvex + 1) % $n]];
                array_splice($ring, $firstConvex, 1);
            } else {
                break;
            }
        }
        if (count($ring) === 3) {
            [$ax, $ay] = $points[$ring[0]];
            [$bx, $by] = $points[$ring[1]];
            [$cx, $cy] = $points[$ring[2]];
            if (($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax) > 0.0) {
                $tris[] = [$ring[0], $ring[1], $ring[2]];
            }
        }
        return $tris;
    }

    /**
     * @param list<Vec2> $ring
     * @return list<Vec2>
     */
    private static function orient(array $ring, bool $ccw): array
    {
        $area = 0.0;
        $n = count($ring);
        for ($i = 0; $i < $n; $i++) {
            $a = $ring[$i];
            $b = $ring[($i + 1) % $n];
            $area += $a->x * $b->y - $b->x * $a->y;
        }
        $isCcw = $area > 0.0;
        return $isCcw === $ccw ? $ring : array_reverse($ring);
    }
}
