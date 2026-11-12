<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Hair cards: one flat strip per guide curve ({@see HairGuide}), the mesh form of hair
 * for real-time characters (a few thousand strips instead of tens of thousands of
 * strands). The strip lies tangent to the scalp (its normal follows the root normal,
 * carried along the curve), narrows toward the tip, and can twist around the guide.
 *
 * UV: u across the card (0..1; with $atlasColumns > 1 each card takes column
 * index % columns of an atlas, so a cutout texture or a procedural pattern can vary per
 * card), v from the root (0) to the tip (1). Normals are the card face normals; tangents
 * (4 floats) are the strand direction, handedness +1, for anisotropic highlights.
 * Draw with a two-sided Material with an alpha cutoff; the root-to-tip parameter in v
 * and the across parameter in u are enough for a procedural strand pattern.
 *
 * Pure and deterministic; no random state. Game-agnostic: sizes are parameters.
 */
final class HairCardMesh
{
    /**
     * @param list<HairGuide> $guides
     * @param float $width         Card width at the root.
     * @param int   $segments      Quads along each card; 0 = one per guide span.
     * @param float $tipWidth      Width at the tip as a fraction of the root width (0..1).
     * @param float $taper         Exponent of the narrowing along the card (1 = linear, >1 = stays wide longer).
     * @param float $twist         Turns of rotation around the guide from root to tip.
     * @param int   $atlasColumns  Texture/pattern columns the cards are spread over (1 = u spans 0..1 on every card).
     */
    public static function generate(
        array $guides,
        float $width,
        int $segments = 0,
        float $tipWidth = 0.25,
        float $taper = 1.0,
        float $twist = 0.0,
        int $atlasColumns = 1,
    ): MeshData {
        $vertices = [];
        $normals = [];
        $uvs = [];
        $tangents = [];
        $indices = [];
        $columns = max(1, $atlasColumns);
        $tipWidth = max(0.0, min(1.0, $tipWidth));

        foreach ($guides as $gi => $guide) {
            $span = count($guide->points) - 1;
            if ($span < 1) {
                continue;
            }
            $segs = $segments > 0 ? $segments : $span;
            $curve = $segments > 0 && $segments !== $span ? self::resample($guide->points, $segs) : $guide->points;
            $col = $gi % $columns;
            $base = intdiv(count($vertices), 3);
            // Frame carried along the curve: the card normal is the root normal made perpendicular to the tangent.
            $hint = $guide->rootNormal;
            for ($k = 0; $k <= $segs; $k++) {
                $f = $k / $segs;
                $t = self::tangentAt($curve, $k);
                $d = self::dot($hint, $t);
                $n = self::norm([$hint[0] - $t[0] * $d, $hint[1] - $t[1] * $d, $hint[2] - $t[2] * $d], $t);
                $hint = $n;
                $a = self::cross($t, $n);
                if ($twist !== 0.0) {
                    $ang = $twist * 2.0 * M_PI * $f;
                    $c = cos($ang);
                    $s = sin($ang);
                    $a2 = [$a[0] * $c + $n[0] * $s, $a[1] * $c + $n[1] * $s, $a[2] * $c + $n[2] * $s];
                    $n = self::cross($a2, $t);
                    $a = $a2;
                }
                $hw = 0.5 * $width * (1.0 - (1.0 - $tipWidth) * ($taper === 1.0 ? $f : $f ** $taper));
                $q = $curve[$k];
                array_push($vertices, $q[0] - $a[0] * $hw, $q[1] - $a[1] * $hw, $q[2] - $a[2] * $hw);
                array_push($vertices, $q[0] + $a[0] * $hw, $q[1] + $a[1] * $hw, $q[2] + $a[2] * $hw);
                array_push($normals, $n[0], $n[1], $n[2], $n[0], $n[1], $n[2]);
                array_push($uvs, $col / $columns, $f, ($col + 1) / $columns, $f);
                array_push($tangents, $t[0], $t[1], $t[2], 1.0, $t[0], $t[1], $t[2], 1.0);
            }
            for ($k = 0; $k < $segs; $k++) {
                $i = $base + $k * 2;
                array_push($indices, $i, $i + 1, $i + 3, $i, $i + 3, $i + 2);
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices, $tangents === [] ? null : $tangents);
    }

    /**
     * A level of detail: keeps every 2^$level-th guide, widens the remaining cards by
     * sqrt(2^$level) so the coverage stays about the same, and halves the segments per
     * level (never below one). Level 0 is {@see generate()} with the full set.
     *
     * @param list<HairGuide> $guides
     */
    public static function generateLod(
        array $guides,
        float $width,
        int $level,
        int $segments = 0,
        float $tipWidth = 0.25,
        float $taper = 1.0,
        float $twist = 0.0,
        int $atlasColumns = 1,
    ): MeshData {
        $level = max(0, $level);
        if ($level === 0) {
            return self::generate($guides, $width, $segments, $tipWidth, $taper, $twist, $atlasColumns);
        }
        $stride = 1 << $level;
        $kept = [];
        foreach ($guides as $i => $guide) {
            if ($i % $stride === 0) {
                $kept[] = $guide;
            }
        }
        $baseSegs = $segments > 0 ? $segments : (isset($guides[0]) ? count($guides[0]->points) - 1 : 1);
        $segs = max(1, intdiv($baseSegs, $stride));

        return self::generate($kept, $width * sqrt((float) $stride), $segs, $tipWidth, $taper, $twist, $atlasColumns);
    }

    /**
     * Points at equal arc-length steps along the polyline.
     *
     * @param list<array{0: float, 1: float, 2: float}> $pts
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function resample(array $pts, int $segs): array
    {
        $cum = [0.0];
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            $cum[$i] = $cum[$i - 1] + sqrt(
                ($pts[$i][0] - $pts[$i - 1][0]) ** 2 + ($pts[$i][1] - $pts[$i - 1][1]) ** 2 + ($pts[$i][2] - $pts[$i - 1][2]) ** 2,
            );
        }
        $total = $cum[count($pts) - 1];
        $out = [$pts[0]];
        $j = 1;
        for ($k = 1; $k < $segs; $k++) {
            $target = $total * $k / $segs;
            while ($j < count($pts) - 1 && $cum[$j] < $target) {
                $j++;
            }
            $seg = $cum[$j] - $cum[$j - 1];
            $e = $seg > 1e-12 ? ($target - $cum[$j - 1]) / $seg : 0.0;
            $out[] = [
                $pts[$j - 1][0] + ($pts[$j][0] - $pts[$j - 1][0]) * $e,
                $pts[$j - 1][1] + ($pts[$j][1] - $pts[$j - 1][1]) * $e,
                $pts[$j - 1][2] + ($pts[$j][2] - $pts[$j - 1][2]) * $e,
            ];
        }
        $out[] = $pts[count($pts) - 1];

        return $out;
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $curve
     * @return array{0: float, 1: float, 2: float}
     */
    private static function tangentAt(array $curve, int $k): array
    {
        $last = count($curve) - 1;
        $a = $curve[max(0, $k - 1)];
        $b = $curve[min($last, $k + 1)];

        return self::norm([$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]], [0.0, -1.0, 0.0]);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
     */
    private static function cross(array $a, array $b): array
    {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     */
    private static function dot(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }

    /**
     * Normalises; a degenerate vector (the hint parallel to the tangent) falls back to
     * any unit vector perpendicular to $t.
     *
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $t
     * @return array{0: float, 1: float, 2: float}
     */
    private static function norm(array $a, array $t): array
    {
        $l = sqrt($a[0] * $a[0] + $a[1] * $a[1] + $a[2] * $a[2]);
        if ($l > 1e-6) {
            return [$a[0] / $l, $a[1] / $l, $a[2] / $l];
        }
        $ref = abs($t[1]) < 0.9 ? [0.0, 1.0, 0.0] : [1.0, 0.0, 0.0];
        $p = self::cross($t, $ref);
        $pl = sqrt($p[0] * $p[0] + $p[1] * $p[1] + $p[2] * $p[2]);

        return $pl > 1e-12 ? [$p[0] / $pl, $p[1] / $pl, $p[2] / $pl] : [0.0, 0.0, 1.0];
    }
}
