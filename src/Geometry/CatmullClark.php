<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Catmull-Clark subdivision of a polygon mesh ({@see PolyMesh}): every level turns each n-gon into n quads and moves
 * the vertices onto a smoother surface; the limit is a C2 B-spline surface except at extraordinary vertices (valence
 * other than four), where it is C1. A quad-dominant base mesh with good edge flow gives a smooth body, face or hand.
 *
 * - Creases: edge sharpness (0 smooth, 1 stays sharp for one level, INF always). Boundary edges are always sharp. Vertex
 *   rules follow Pixar's semi-sharp scheme (dart, crease and corner vertices; fractional sharpness blends).
 * - UV seams: texture coordinates are subdivided as a mesh of their own over the same faces; the seam is its boundary,
 *   so it stays a line and the mesh is split there, positions stay watertight.
 * - Normals and tangents: smooth over the surface, split at edges that are still sharp after the last level; tangents
 *   from the UV derivatives, with handedness in w.
 * - Stencils: {@see SubdivisionResult} gives every subdivided vertex as a combination of base vertices, so attributes of
 *   the base mesh are interpolated, and deformed base positions re-smoothed without subdividing again.
 *
 * Pure and deterministic: no global state, no randomness; the same input gives the same output on every call and thread.
 * Up to three levels (a quad base of N faces gives N * 4^levels quads); the stencils cost memory at the last level, switch
 * them off with $stencils = false when only the surface matters.
 */
final class CatmullClark
{
    public const MAX_LEVELS = 3;

    private function __construct()
    {
    }

    /**
     * @param int  $levels   0..3
     * @param bool $stencils keep the base-vertex combinations of every vertex (see {@see SubdivisionResult})
     */
    public static function subdivide(PolyMesh $base, int $levels = 2, bool $stencils = true): SubdivisionResult
    {
        if ($levels < 0 || $levels > self::MAX_LEVELS) {
            throw new \InvalidArgumentException('CatmullClark: levels must be 0..' . self::MAX_LEVELS);
        }
        $p = $base->positions;
        $faces = $base->faces;
        $sharp = $base->creases;
        $st = null;
        if ($stencils) {
            $st = [];
            for ($v = 0, $n = $base->vertexCount(); $v < $n; $v++) {
                $st[$v] = [$v => 1.0];
            }
        }
        for ($l = 0; $l < $levels; $l++) {
            [$p, $faces, $sharp, $st] = self::refine($p, $faces, $sharp, $st);
        }
        $uvFaces = null;
        $uvs = null;
        if ($base->uvs !== null && $base->faceUvs !== null) {
            $up = [];
            for ($i = 0, $n = intdiv(count($base->uvs), 2); $i < $n; $i++) {
                array_push($up, $base->uvs[$i * 2], $base->uvs[$i * 2 + 1], 0.0);
            }
            $uf = $base->faceUvs;
            $none = null;
            for ($l = 0; $l < $levels; $l++) {
                [$up, $uf, , $none] = self::refine($up, $uf, [], $none);
            }
            $uvFaces = $uf;
            $uvs = $up;
        }

        return self::assemble($p, $faces, $sharp, $uvFaces, $uvs, $levels, $base->vertexCount(), $st);
    }

    /**
     * One level. Returns the new positions, faces, edge sharpness and stencils.
     *
     * @param array<float> $p
     * @param array<int, array<int, int>> $faces
     * @param array<string, float> $sharp
     * @param array<int, array<int, float>>|null $st
     * @return array{0: list<float>, 1: list<list<int>>, 2: array<string, float>, 3: array<int, array<int, float>>|null}
     */
    private static function refine(array $p, array $faces, array $sharp, ?array $st): array
    {
        $vn = intdiv(count($p), 3);
        // Edges: key => [a, b, faces], numbered in order of first use.
        $edges = [];
        foreach ($faces as $f => $face) {
            $n = count($face);
            for ($i = 0; $i < $n; $i++) {
                $a = $face[$i];
                $b = $face[($i + 1) % $n];
                $k = $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
                if (!isset($edges[$k])) {
                    $edges[$k] = [min($a, $b), max($a, $b), []];
                }
                $edges[$k][2][] = $f;
            }
        }
        $edgeId = [];
        $e = 0;
        foreach ($edges as $k => $_) {
            $edgeId[$k] = $vn + $e++;
        }
        $en = $e;
        $faceBase = $vn + $en;

        // Face points as combinations of old vertices.
        $facePoint = [];
        foreach ($faces as $f => $face) {
            $w = 1.0 / count($face);
            $combo = [];
            foreach ($face as $v) {
                $combo[$v] = ($combo[$v] ?? 0.0) + $w;
            }
            $facePoint[$f] = $combo;
        }

        // Effective sharpness per edge (boundary and non-manifold edges are sharp), per-vertex incident edges.
        $edgeSharp = [];
        $vertEdges = [];
        $vertFaces = [];
        foreach ($edges as $k => [$a, $b, $fs]) {
            $s = $sharp[$k] ?? 0.0;
            if (count($fs) !== 2) {
                $s = INF;
            }
            $edgeSharp[$k] = $s;
            $vertEdges[$a][] = $k;
            $vertEdges[$b][] = $k;
        }
        foreach ($faces as $f => $face) {
            foreach ($face as $v) {
                $vertFaces[$v][] = $f;
            }
        }

        $combos = [];
        // Old vertices.
        for ($v = 0; $v < $vn; $v++) {
            $incident = $vertEdges[$v] ?? [];
            $k = count($incident);
            if ($k === 0) {
                $combos[$v] = [$v => 1.0];
                continue;
            }
            $creases = [];
            foreach ($incident as $ek) {
                if ($edgeSharp[$ek] > 0.0) {
                    [$a, $b] = $edges[$ek];
                    $creases[] = [$a === $v ? $b : $a, $edgeSharp[$ek]];
                }
            }
            $c = count($creases);
            $sigma = 0.0;
            $crease = null;
            if ($c >= 3) {
                $sigma = min(1.0, array_sum(array_column($creases, 1)) / $c);
                $crease = [$v => 1.0];
            } elseif ($c === 2) {
                $sigma = min(1.0, ($creases[0][1] + $creases[1][1]) / 2.0);
                $crease = [$v => 0.75];
                $crease[$creases[0][0]] = ($crease[$creases[0][0]] ?? 0.0) + 0.125;
                $crease[$creases[1][0]] = ($crease[$creases[1][0]] ?? 0.0) + 0.125;
            }
            $combo = [];
            if ($sigma < 1.0) {
                // Smooth rule: (F + 2R + (n - 3) P) / n with n the valence.
                $fs = $vertFaces[$v] ?? [];
                $nf = count($fs);
                $acc = [$v => ($k - 3.0) / $k];
                foreach ($incident as $ek) {
                    [$a, $b] = $edges[$ek];
                    $o = $a === $v ? $b : $a;
                    $wgt = 2.0 / $k / $k;
                    $acc[$v] = ($acc[$v] ?? 0.0) + $wgt * 0.5;
                    $acc[$o] = ($acc[$o] ?? 0.0) + $wgt * 0.5;
                }
                foreach ($fs as $f) {
                    foreach ($facePoint[$f] as $vv => $w) {
                        $acc[$vv] = ($acc[$vv] ?? 0.0) + $w / $k / max($nf, 1);
                    }
                }
                foreach ($acc as $vv => $w) {
                    $combo[$vv] = $w * (1.0 - $sigma);
                }
            }
            if ($sigma > 0.0 && $crease !== null) {
                foreach ($crease as $vv => $w) {
                    $combo[$vv] = ($combo[$vv] ?? 0.0) + $w * $sigma;
                }
            }
            $combos[$v] = $combo;
        }
        // Edge points.
        foreach ($edges as $k => [$a, $b, $fs]) {
            $s = $edgeSharp[$k];
            $sigma = min(1.0, $s);
            $combo = [$a => 0.5 * $sigma, $b => 0.5 * $sigma];
            if ($sigma < 1.0 && count($fs) === 2) {
                $smooth = [$a => 0.25, $b => 0.25];
                foreach ($fs as $f) {
                    foreach ($facePoint[$f] as $vv => $w) {
                        $smooth[$vv] = ($smooth[$vv] ?? 0.0) + 0.25 * $w;
                    }
                }
                foreach ($smooth as $vv => $w) {
                    $combo[$vv] = ($combo[$vv] ?? 0.0) + $w * (1.0 - $sigma);
                }
            }
            $combos[$edgeId[$k]] = $combo;
        }
        foreach ($facePoint as $f => $combo) {
            $combos[$faceBase + $f] = $combo;
        }

        $total = $faceBase + count($faces);
        $np = [];
        $nst = $st === null ? null : [];
        for ($i = 0; $i < $total; $i++) {
            $x = $y = $z = 0.0;
            foreach ($combos[$i] as $v => $w) {
                $x += $w * $p[$v * 3];
                $y += $w * $p[$v * 3 + 1];
                $z += $w * $p[$v * 3 + 2];
            }
            $np[] = $x;
            $np[] = $y;
            $np[] = $z;
            if ($st !== null && $nst !== null) {
                $acc = [];
                foreach ($combos[$i] as $v => $w) {
                    foreach ($st[$v] as $b => $sw) {
                        $acc[$b] = ($acc[$b] ?? 0.0) + $w * $sw;
                    }
                }
                $nst[$i] = $acc;
            }
        }

        $nf = [];
        foreach ($faces as $f => $face) {
            $n = count($face);
            $fp = $faceBase + $f;
            for ($i = 0; $i < $n; $i++) {
                $a = $face[$i];
                $b = $face[($i + 1) % $n];
                $c = $face[($i + $n - 1) % $n];
                $nf[] = [$a, $edgeId[$a < $b ? "{$a}:{$b}" : "{$b}:{$a}"], $fp, $edgeId[$a < $c ? "{$a}:{$c}" : "{$c}:{$a}"]];
            }
        }

        // Sharpness of the child edges: the half edges a-E and E-b keep s - 1.
        $ns = [];
        foreach ($edges as $k => [$a, $b]) {
            $s = $sharp[$k] ?? 0.0;
            if ($s <= 0.0) {
                continue;
            }
            $child = is_infinite($s) ? INF : $s - 1.0;
            if ($child <= 0.0) {
                continue;
            }
            $m = $edgeId[$k];
            $ns[$a < $m ? "{$a}:{$m}" : "{$m}:{$a}"] = $child;
            $ns[$b < $m ? "{$b}:{$m}" : "{$m}:{$b}"] = $child;
        }

        return [$np, $nf, $ns, $nst];
    }

    /**
     * @param array<float> $p
     * @param list<list<int>> $faces
     * @param array<string, float> $sharp
     * @param list<list<int>>|null $uvFaces
     * @param list<float>|null $uvPositions
     * @param array<int, array<int, float>>|null $st
     */
    private static function assemble(array $p, array $faces, array $sharp, ?array $uvFaces, ?array $uvPositions, int $levels, int $baseCount, ?array $st): SubdivisionResult
    {
        $vn = intdiv(count($p), 3);
        // Corners and edges.
        $faceStart = [];
        $corner = 0;
        foreach ($faces as $f => $face) {
            $faceStart[$f] = $corner;
            $corner += count($face);
        }
        $parent = range(0, max(0, $corner - 1));
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $edgeFaces = [];
        foreach ($faces as $f => $face) {
            $n = count($face);
            for ($i = 0; $i < $n; $i++) {
                $a = $face[$i];
                $b = $face[($i + 1) % $n];
                $edgeFaces[$a < $b ? "{$a}:{$b}" : "{$b}:{$a}"][] = $f;
            }
        }
        foreach ($edgeFaces as $k => $fs) {
            if (count($fs) !== 2 || ($sharp[$k] ?? 0.0) > 0.0) {
                continue;
            }
            [$a, $b] = array_map('intval', explode(':', $k));
            foreach ([$a, $b] as $v) {
                $c1 = $faceStart[$fs[0]] + array_search($v, $faces[$fs[0]], true);
                $c2 = $faceStart[$fs[1]] + array_search($v, $faces[$fs[1]], true);
                $r1 = $find($c1);
                $r2 = $find($c2);
                if ($r1 !== $r2) {
                    $parent[max($r1, $r2)] = min($r1, $r2);
                }
            }
        }
        // Face normals (Newell, area weighted), summed per smoothing group.
        $acc = [];
        foreach ($faces as $f => $face) {
            $n = count($face);
            $nx = $ny = $nz = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $a = $face[$i] * 3;
                $b = $face[($i + 1) % $n] * 3;
                $nx += ($p[$a + 1] - $p[$b + 1]) * ($p[$a + 2] + $p[$b + 2]);
                $ny += ($p[$a + 2] - $p[$b + 2]) * ($p[$a] + $p[$b]);
                $nz += ($p[$a] - $p[$b]) * ($p[$a + 1] + $p[$b + 1]);
            }
            for ($i = 0; $i < $n; $i++) {
                $r = $find($faceStart[$f] + $i);
                if (!isset($acc[$r])) {
                    $acc[$r] = [0.0, 0.0, 0.0];
                }
                $acc[$r][0] += $nx;
                $acc[$r][1] += $ny;
                $acc[$r][2] += $nz;
            }
        }
        // Output vertices: one per (smoothing group, UV vertex).
        $vertices = [];
        $normals = [];
        $uvOut = [];
        $outToVertex = [];
        $map = [];
        $indices = [];
        foreach ($faces as $f => $face) {
            $n = count($face);
            $co = array_fill(0, $n, 0);
            for ($i = 0; $i < $n; $i++) {
                $r = $find($faceStart[$f] + $i);
                $uvIdx = $uvFaces !== null ? $uvFaces[$f][$i] : -1;
                $key = $r . ':' . $uvIdx;
                $out = $map[$key] ?? null;
                if ($out === null) {
                    $out = intdiv(count($vertices), 3);
                    $map[$key] = $out;
                    $v = $face[$i];
                    array_push($vertices, $p[$v * 3], $p[$v * 3 + 1], $p[$v * 3 + 2]);
                    [$nx, $ny, $nz] = $acc[$r];
                    $l = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                    array_push($normals, ...($l > 1e-20 ? [$nx / $l, $ny / $l, $nz / $l] : [0.0, 1.0, 0.0]));
                    if ($uvPositions !== null) {
                        array_push($uvOut, $uvPositions[$uvIdx * 3], $uvPositions[$uvIdx * 3 + 1]);
                    }
                    $outToVertex[] = $v;
                }
                $co[$i] = $out;
            }
            // Quads (and any n-gon) as a fan from the first corner.
            for ($i = 1; $i < $n - 1; $i++) {
                array_push($indices, $co[0], $co[$i], $co[$i + 1]);
            }
        }
        $tangents = null;
        if ($uvPositions !== null) {
            $tangents = self::tangents($vertices, $normals, $uvOut, $indices);
        }
        $mesh = new MeshData($vertices, $normals, $uvOut, $indices, $tangents);
        $sIdx = [];
        $sW = [];
        if ($st !== null) {
            for ($v = 0; $v < $vn; $v++) {
                ksort($st[$v]);
                $sIdx[] = array_keys($st[$v]);
                $sW[] = array_values($st[$v]);
            }
        }

        return new SubdivisionResult($mesh, $levels, $baseCount, $vn, $sIdx, $sW, $outToVertex);
    }

    /**
     * Per-vertex tangents (xyz + handedness) from the UV derivatives of the triangles around a vertex.
     *
     * @param list<float> $v
     * @param list<float> $n
     * @param list<float> $uv
     * @param list<int> $idx
     * @return list<float>
     */
    private static function tangents(array $v, array $n, array $uv, array $idx): array
    {
        $vc = intdiv(count($v), 3);
        $t = array_fill(0, $vc * 3, 0.0);
        $b = array_fill(0, $vc * 3, 0.0);
        for ($i = 0, $m = count($idx) - 2; $i < $m; $i += 3) {
            [$a, $c, $d] = [$idx[$i], $idx[$i + 1], $idx[$i + 2]];
            $e1 = [$v[$c * 3] - $v[$a * 3], $v[$c * 3 + 1] - $v[$a * 3 + 1], $v[$c * 3 + 2] - $v[$a * 3 + 2]];
            $e2 = [$v[$d * 3] - $v[$a * 3], $v[$d * 3 + 1] - $v[$a * 3 + 1], $v[$d * 3 + 2] - $v[$a * 3 + 2]];
            $du1 = $uv[$c * 2] - $uv[$a * 2];
            $dv1 = $uv[$c * 2 + 1] - $uv[$a * 2 + 1];
            $du2 = $uv[$d * 2] - $uv[$a * 2];
            $dv2 = $uv[$d * 2 + 1] - $uv[$a * 2 + 1];
            $det = $du1 * $dv2 - $du2 * $dv1;
            if (abs($det) < 1e-20) {
                continue;
            }
            $r = 1.0 / $det;
            $tx = ($e1[0] * $dv2 - $e2[0] * $dv1) * $r;
            $ty = ($e1[1] * $dv2 - $e2[1] * $dv1) * $r;
            $tz = ($e1[2] * $dv2 - $e2[2] * $dv1) * $r;
            $bx = ($e2[0] * $du1 - $e1[0] * $du2) * $r;
            $by = ($e2[1] * $du1 - $e1[1] * $du2) * $r;
            $bz = ($e2[2] * $du1 - $e1[2] * $du2) * $r;
            foreach ([$a, $c, $d] as $q) {
                $t[$q * 3] += $tx;
                $t[$q * 3 + 1] += $ty;
                $t[$q * 3 + 2] += $tz;
                $b[$q * 3] += $bx;
                $b[$q * 3 + 1] += $by;
                $b[$q * 3 + 2] += $bz;
            }
        }
        $out = [];
        for ($q = 0; $q < $vc; $q++) {
            $nx = $n[$q * 3];
            $ny = $n[$q * 3 + 1];
            $nz = $n[$q * 3 + 2];
            $tx = $t[$q * 3];
            $ty = $t[$q * 3 + 1];
            $tz = $t[$q * 3 + 2];
            $d = $nx * $tx + $ny * $ty + $nz * $tz;
            $tx -= $nx * $d;
            $ty -= $ny * $d;
            $tz -= $nz * $d;
            $l = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
            if ($l < 1e-12) {
                // No usable UV derivative: any unit vector across the normal.
                if (abs($ny) < 0.9) {
                    [$tx, $ty, $tz] = [$nz, 0.0, -$nx];
                } else {
                    [$tx, $ty, $tz] = [1.0, 0.0, 0.0];
                }
                $l = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
            }
            $tx /= $l;
            $ty /= $l;
            $tz /= $l;
            // Handedness: does (n x t) point along the bitangent?
            $cx = $ny * $tz - $nz * $ty;
            $cy = $nz * $tx - $nx * $tz;
            $cz = $nx * $ty - $ny * $tx;
            $w = ($cx * $b[$q * 3] + $cy * $b[$q * 3 + 1] + $cz * $b[$q * 3 + 2]) < 0.0 ? -1.0 : 1.0;
            array_push($out, $tx, $ty, $tz, $w);
        }

        return $out;
    }
}
