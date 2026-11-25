<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * "Rectangle minus holes": the axis-aligned rectangles that cover a rectangle except for any number of rectangular holes,
 * e.g. the wall boxes around the doors and windows of a facade. A pure function of its arguments (no scene classes, no random
 * state): the same input gives the same list, in a worker too.
 *
 * The rectangle is swept along u; every slab between two hole edges keeps what lies above, below and between the holes that
 * cover it, and neighbouring slabs with identical cover are merged. The pieces never overlap, cover exactly the rectangle minus
 * the union of the holes (holes may overlap each other and may stick out of the rectangle), and share whole borders with the
 * holes. Rectangles are `[u0, u1, v0, v1]` (u horizontal along the wall, v vertical).
 */
final class RectCut
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $rect `[u0, u1, v0, v1]`
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $holes `[u0, u1, v0, v1]` each
     * @param float $minSize pieces thinner than this (in u or v) are dropped as slivers
     * @return list<array{0: float, 1: float, 2: float, 3: float}> sorted by u, then v
     */
    public static function pieces(array $rect, array $holes, float $minSize = 1e-3): array
    {
        [$u0, $u1, $v0, $v1] = $rect;
        if ($u1 <= $u0 || $v1 <= $v0) {
            return [];
        }
        $edges = [$u0, $u1];
        $clipped = [];
        foreach ($holes as [$h0, $h1, $hv0, $hv1]) {
            $hv0 = max($hv0, $v0);
            $hv1 = min($hv1, $v1);
            if ($h1 <= $u0 || $h0 >= $u1 || $hv1 <= $hv0 || $h1 <= $h0) {
                continue;
            }
            $h0 = max($h0, $u0);
            $h1 = min($h1, $u1);
            $clipped[] = [$h0, $h1, $hv0, $hv1];
            $edges[] = $h0;
            $edges[] = $h1;
        }
        sort($edges);

        // Slabs between distinct edges, each with the merged (disjoint, sorted) hole intervals that cover it.
        $slabs = [];
        for ($i = 0, $n = count($edges); $i + 1 < $n; $i++) {
            if ($edges[$i + 1] <= $edges[$i]) {
                continue;
            }
            $mid = ($edges[$i] + $edges[$i + 1]) * 0.5;
            $ivs = [];
            foreach ($clipped as [$h0, $h1, $hv0, $hv1]) {
                if ($mid > $h0 && $mid < $h1) {
                    $ivs[] = [$hv0, $hv1];
                }
            }
            $ivs = self::union($ivs);
            $last = count($slabs) - 1;
            if ($last >= 0 && $slabs[$last]['ivs'] === $ivs) {
                $slabs[$last]['u1'] = $edges[$i + 1];
            } else {
                $slabs[] = ['u0' => $edges[$i], 'u1' => $edges[$i + 1], 'ivs' => $ivs];
            }
        }

        $out = [];
        foreach ($slabs as $slab) {
            $v = $v0;
            foreach ($slab['ivs'] as [$a, $b]) {
                self::emit($out, $slab['u0'], $slab['u1'], $v, $a, $minSize);
                $v = $b;
            }
            self::emit($out, $slab['u0'], $slab['u1'], $v, $v1, $minSize);
        }

        return $out;
    }

    /**
     * Total area of a list of rectangles (a test and sanity helper).
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $rects
     */
    public static function area(array $rects): float
    {
        $sum = 0.0;
        foreach ($rects as [$a, $b, $c, $d]) {
            $sum += ($b - $a) * ($d - $c);
        }

        return $sum;
    }

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $out
     */
    private static function emit(array &$out, float $u0, float $u1, float $v0, float $v1, float $minSize): void
    {
        if ($u1 - $u0 >= $minSize && $v1 - $v0 >= $minSize) {
            $out[] = [$u0, $u1, $v0, $v1];
        }
    }

    /**
     * @param list<array{0: float, 1: float}> $ivs
     * @return list<array{0: float, 1: float}> disjoint intervals, sorted
     */
    private static function union(array $ivs): array
    {
        if (count($ivs) < 2) {
            return $ivs;
        }
        usort($ivs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [$ivs[0]];
        foreach ($ivs as $k => [$a, $b]) {
            if ($k === 0) {
                continue;
            }
            $last = count($out) - 1;
            if ($a <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }
}
