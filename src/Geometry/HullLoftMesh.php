<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Runtime\PerfProfiler;

/**
 * Loft a symmetric ship hull through a list of {@see HullStation} sections along
 * the Z axis – the shape tool for what an {@see EllipseLoftMesh} cannot make:
 * a hull whose top edge (sheer) rises toward one end, whose keel rocks upward
 * toward both ends, and whose sides flare out above the waterline. Each station
 * gives the half-outline (half-breadth, height) from keel to sheer; the mesh
 * mirrors it about x = 0 and joins neighbouring stations with quads.
 *
 * Stations must be sorted by increasing $z; the first and last may be knife
 * edges (all half-breadths 0). The lowest point of every station sits on the
 * centre plane (half-breadth 0) – the keel line – so the underside is closed;
 * the top edge stays open (a deck mesh closes it) unless $wall > 0.
 *
 * `$fromRow`/`$toRow` cut a band out of the profile (point indices, inclusive),
 * so one loft can be split into separate meshes for different paints (an
 * antifouling band, a waterline stripe, the topsides) that share every vertex
 * position and therefore meet without gaps.
 *
 * `$wall > 0` adds the inner skin of the band, inset by `$wall` metres, plus a
 * rim over the top edge – a bulwark with real thickness that is closed seen
 * from the deck (no back-face see-through). The rim and the two skins keep
 * their own vertices (crisp edge); each skin is smooth-shaded, and the keel
 * line shares one normal across the centre plane.
 *
 * Winding follows {@see LatheMesh} / {@see EllipseLoftMesh} (the cross product
 * of a front-facing triangle points into the solid).
 */
final class HullLoftMesh
{
    /**
     * @param list<HullStation> $stations At least 2, increasing z, equal point counts.
     */
    public static function generate(array $stations, int $fromRow = 0, ?int $toRow = null, float $wall = 0.0): MeshData
    {
        return PerfProfiler::section('mesh.generate.hull_loft', static fn(): MeshData
            => self::generateImpl($stations, $fromRow, $toRow, max(0.0, $wall)));
    }

    /**
     * @param list<HullStation> $stations
     */
    private static function generateImpl(array $stations, int $fromRow, ?int $toRow, float $wall): MeshData
    {
        $n = count($stations);
        if ($n < 2) {
            return new MeshData([], [], [], []);
        }
        $rowsTotal = count($stations[0]->points);
        foreach ($stations as $i => $s) {
            if (count($s->points) !== $rowsTotal) {
                throw new \InvalidArgumentException("HullStation {$i} has " . count($s->points) . " points, expected {$rowsTotal}");
            }
            if ($i > 0 && $s->z <= $stations[$i - 1]->z) {
                throw new \InvalidArgumentException('HullStation z must increase');
            }
        }
        $from = max(0, $fromRow);
        $to = min($rowsTotal - 1, $toRow ?? $rowsTotal - 1);
        if ($to - $from < 1) {
            return new MeshData([], [], [], []);
        }

        $out = ['v' => [], 'n' => [], 'u' => [], 'i' => []];
        self::skin($out, $stations, $from, $to, 0.0, false);
        if ($wall > 0.0) {
            self::skin($out, $stations, $from, $to, $wall, true);
            self::rim($out, $stations, $to, $wall);
        }

        return new MeshData($out['v'], $out['n'], $out['u'], $out['i']);
    }

    /**
     * One smooth-shaded skin (both sides) appended to $out.
     *
     * @param array{v: list<float>, n: list<float>, u: list<float>, i: list<int>} $out
     * @param list<HullStation> $stations
     */
    private static function skin(array &$out, array $stations, int $from, int $to, float $inset, bool $inward): void
    {
        $n = count($stations);
        $rows = $to - $from + 1;
        $base = intdiv(count($out['v']), 3);
        $pos = [];
        $uvs = [];
        for ($i = 0; $i < $n; $i++) {
            $arc = 0.0;
            $prev = null;
            for ($r = 0; $r < $rows; $r++) {
                [$b, $y] = $stations[$i]->points[$from + $r];
                $x = $b > 0.0 ? max(0.0, $b - $inset) : 0.0;
                if ($prev !== null) {
                    $arc += hypot($x - $prev[0], $y - $prev[1]);
                }
                $prev = [$x, $y];
                foreach ([1.0, -1.0] as $side) {
                    array_push($pos, $side * $x, $y, $stations[$i]->z);
                    array_push($uvs, $stations[$i]->z, $side > 0 ? $arc : -$arc);
                }
            }
        }
        $at = static fn(int $i, int $r, int $side): int => ($i * $rows + $r) * 2 + $side;

        $idx = [];
        for ($i = 0; $i < $n - 1; $i++) {
            for ($r = 0; $r < $rows - 1; $r++) {
                foreach ([0, 1] as $side) {
                    $a = $at($i, $r, $side);
                    $b = $at($i + 1, $r, $side);
                    $c = $at($i + 1, $r + 1, $side);
                    $d = $at($i, $r + 1, $side);
                    // +X side outer: (a,b,c),(a,c,d); the −X side and the inner skin flip it.
                    $flip = ($side === 1) !== $inward;
                    foreach ($flip ? [[$a, $c, $b], [$a, $d, $c]] : [[$a, $b, $c], [$a, $c, $d]] as $t) {
                        // Skip slivers and triangles lying in the centre plane (stem/stern posts).
                        $flat = abs($pos[$t[0] * 3]) < 1e-9 && abs($pos[$t[1] * 3]) < 1e-9 && abs($pos[$t[2] * 3]) < 1e-9;
                        if (!$flat && self::area2($pos, $t[0], $t[1], $t[2]) > 1e-14) {
                            array_push($idx, $base + $t[0], $base + $t[1], $base + $t[2]);
                        }
                    }
                }
            }
        }

        // Keel line shares one normal across the centre plane (x = 0 columns).
        $normals = self::smoothNormals($pos, $idx, $base);
        $groups = [];
        $count = intdiv(count($pos), 3);
        for ($k = 0; $k < $count; $k++) {
            if (abs($pos[$k * 3]) < 1e-9) {
                $key = round($pos[$k * 3 + 1] * 1e5) . '|' . round($pos[$k * 3 + 2] * 1e5);
                $groups[$key][] = $k;
            }
        }
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            $sum = [0.0, 0.0, 0.0];
            foreach ($members as $k) {
                for ($c = 0; $c < 3; $c++) {
                    $sum[$c] += $normals[$k * 3 + $c];
                }
            }
            foreach ($members as $k) {
                for ($c = 0; $c < 3; $c++) {
                    $normals[$k * 3 + $c] = $sum[$c];
                }
            }
        }
        $normals = self::normalize($normals);

        array_push($out['v'], ...$pos);
        array_push($out['n'], ...$normals);
        array_push($out['u'], ...$uvs);
        array_push($out['i'], ...$idx);
    }

    /**
     * Flat strip over the top edge between the outer and the inset line.
     *
     * @param array{v: list<float>, n: list<float>, u: list<float>, i: list<int>} $out
     * @param list<HullStation> $stations
     */
    private static function rim(array &$out, array $stations, int $topRow, float $wall): void
    {
        $n = count($stations);
        $base = intdiv(count($out['v']), 3);
        $pos = [];
        $uvs = [];
        foreach ($stations as $s) {
            [$b, $y] = $s->points[$topRow];
            $inner = $b > 0.0 ? max(0.0, $b - $wall) : 0.0;
            foreach ([1.0, -1.0] as $side) {
                array_push($pos, $side * $b, $y, $s->z, $side * $inner, $y, $s->z);
                array_push($uvs, $s->z, 0.0, $s->z, $wall);
            }
        }
        $idx = [];
        for ($i = 0; $i < $n - 1; $i++) {
            foreach ([0, 1] as $side) {
                // per station and side: outer, inner
                $a = ($i * 2 + $side) * 2;
                $b = (($i + 1) * 2 + $side) * 2;
                $c = $b + 1;
                $d = $a + 1;
                foreach ($side === 1 ? [[$a, $c, $b], [$a, $d, $c]] : [[$a, $b, $c], [$a, $c, $d]] as $t) {
                    if (self::area2($pos, $t[0], $t[1], $t[2]) > 1e-14) {
                        array_push($idx, $base + $t[0], $base + $t[1], $base + $t[2]);
                    }
                }
            }
        }
        $normals = self::normalize(self::smoothNormals($pos, $idx, $base));
        array_push($out['v'], ...$pos);
        array_push($out['n'], ...$normals);
        array_push($out['u'], ...$uvs);
        array_push($out['i'], ...$idx);
    }

    /**
     * @param array<int, float> $pos
     */
    private static function area2(array $pos, int $a, int $b, int $c): float
    {
        $ux = $pos[$b * 3] - $pos[$a * 3];
        $uy = $pos[$b * 3 + 1] - $pos[$a * 3 + 1];
        $uz = $pos[$b * 3 + 2] - $pos[$a * 3 + 2];
        $wx = $pos[$c * 3] - $pos[$a * 3];
        $wy = $pos[$c * 3 + 1] - $pos[$a * 3 + 1];
        $wz = $pos[$c * 3 + 2] - $pos[$a * 3 + 2];

        return ($uy * $wz - $uz * $wy) ** 2 + ($uz * $wx - $ux * $wz) ** 2 + ($ux * $wy - $uy * $wx) ** 2;
    }

    /**
     * Area-weighted, unnormalised vertex normals of a patch whose indices are
     * offset by $base. The cross product points into the solid, hence the negation.
     *
     * @param array<int, float> $pos
     * @param array<int, int> $idx
     * @return array<int, float>
     */
    private static function smoothNormals(array $pos, array $idx, int $base): array
    {
        $normals = array_fill(0, count($pos), 0.0);
        for ($t = 0, $m = count($idx); $t < $m; $t += 3) {
            $ia = ($idx[$t] - $base) * 3;
            $ib = ($idx[$t + 1] - $base) * 3;
            $ic = ($idx[$t + 2] - $base) * 3;
            $ux = $pos[$ib] - $pos[$ia];
            $uy = $pos[$ib + 1] - $pos[$ia + 1];
            $uz = $pos[$ib + 2] - $pos[$ia + 2];
            $wx = $pos[$ic] - $pos[$ia];
            $wy = $pos[$ic + 1] - $pos[$ia + 1];
            $wz = $pos[$ic + 2] - $pos[$ia + 2];
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
        for ($o = 0, $m = count($normals); $o < $m; $o += 3) {
            $len = sqrt($normals[$o] ** 2 + $normals[$o + 1] ** 2 + $normals[$o + 2] ** 2);
            if ($len > 1e-12) {
                $normals[$o] /= $len;
                $normals[$o + 1] /= $len;
                $normals[$o + 2] /= $len;
            } else {
                $normals[$o] = 0.0;
                $normals[$o + 1] = 1.0;
                $normals[$o + 2] = 0.0;
            }
        }

        return $normals;
    }
}
