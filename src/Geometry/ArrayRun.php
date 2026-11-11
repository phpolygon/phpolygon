<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * Instance one MeshData repeatedly along a line or polyline and merge the
 * copies into a single MeshData (balusters, fence posts, ribs, bricks).
 *
 * Step 1, placements(): sample positions along the path.
 *  - $count only:            $count points spread evenly from first to last
 *                            point (inclusive; 1 = the start).
 *  - $spacing only:          a point every $spacing metres from the start,
 *                            floor(length / spacing) + 1 points.
 *  - $spacing + $fit:        spacing is adjusted so the points still hit the
 *                            start AND the end: round(length / spacing) + 1.
 *  - $spacing + $centered:   as "spacing only" but the run is centred on the path.
 *  - $count + $spacing:      exactly $count points $spacing apart (centred if asked).
 * Every placement carries the path tangent (unit) at its position.
 *
 * Step 2, build(): each placement gets world = Translate(position) * Rotation
 * * local, where Rotation is a yaw about Y that turns local +X onto the path
 * tangent (horizontal part; $align = false keeps the mesh unrotated) and
 * local is the optional per-instance callable (index, placement) -> ?Mat4,
 * applied first, for alternating flips, scale variation and offsets.
 * Output tangents are recomputed when the source mesh had them.
 *
 * Pure functions, no static state. Placement order and the output are fully
 * determined by the inputs (a seeded Rng can drive the callable).
 */
final class ArrayRun
{
    /**
     * @param list<Vec3> $path At least 2 points (a line is a 2-point path).
     * @return list<array{position: Vec3, tangent: Vec3}>
     */
    public static function placements(
        array $path,
        ?int $count = null,
        ?float $spacing = null,
        bool $fit = false,
        bool $centered = false,
    ): array {
        $k = count($path);
        if ($k < 2) {
            return [];
        }
        $cum = [0.0];
        for ($i = 1; $i < $k; $i++) {
            $cum[$i] = $cum[$i - 1] + $path[$i]->sub($path[$i - 1])->length();
        }
        $total = $cum[$k - 1];

        if ($spacing === null || $spacing <= 0.0) {
            $n = max(1, $count ?? 2);
            $step = $n > 1 ? $total / ($n - 1) : 0.0;
            $start = 0.0;
        } elseif ($count !== null) {
            $n = max(1, $count);
            $step = $spacing;
            $start = $centered ? ($total - $step * ($n - 1)) / 2.0 : 0.0;
        } elseif ($fit) {
            $segs = max(1, (int) round($total / $spacing));
            $n = $segs + 1;
            $step = $total / $segs;
            $start = 0.0;
        } else {
            $n = (int) floor($total / $spacing + 1e-9) + 1;
            $step = $spacing;
            $start = $centered ? ($total - $step * ($n - 1)) / 2.0 : 0.0;
        }

        $out = [];
        $seg = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = max(0.0, min($total, $start + $step * $i));
            while ($seg < $k - 2 && $cum[$seg + 1] <= $d) {
                $seg++;
            }
            $a = $path[$seg];
            $b = $path[$seg + 1];
            $segLen = $cum[$seg + 1] - $cum[$seg];
            $t = $segLen > 1e-12 ? ($d - $cum[$seg]) / $segLen : 0.0;
            $dir = $b->sub($a);
            $dir = $dir->lengthSquared() > 1e-18 ? $dir->normalize() : new Vec3(1.0, 0.0, 0.0);
            $out[] = ['position' => $a->lerp($b, $t), 'tangent' => $dir];
        }
        return $out;
    }

    /**
     * @param list<array{position: Vec3, tangent: Vec3}> $placements
     * @param (callable(int, array{position: Vec3, tangent: Vec3}): ?Mat4)|null $local
     */
    public static function build(MeshData $mesh, array $placements, bool $align = true, ?callable $local = null): MeshData
    {
        $parts = [];
        foreach ($placements as $i => $pl) {
            $m = Mat4::translation($pl['position']->x, $pl['position']->y, $pl['position']->z);
            if ($align) {
                $t = $pl['tangent'];
                if ($t->x * $t->x + $t->z * $t->z > 1e-12) {
                    $m = $m->multiply(Mat4::rotationY(atan2(-$t->z, $t->x)));
                }
            }
            if ($local !== null) {
                $l = $local($i, $pl);
                if ($l !== null) {
                    $m = $m->multiply($l);
                }
            }
            $parts[] = $mesh->transformed($m);
        }
        $merged = MeshData::merge(...$parts);
        return $mesh->tangents !== null ? $merged->withComputedTangents() : $merged;
    }

    /**
     * Straight run between two points.
     *
     * @param (callable(int, array{position: Vec3, tangent: Vec3}): ?Mat4)|null $local
     */
    public static function line(
        MeshData $mesh,
        Vec3 $from,
        Vec3 $to,
        ?int $count = null,
        ?float $spacing = null,
        bool $fit = false,
        bool $centered = false,
        bool $align = true,
        ?callable $local = null,
    ): MeshData {
        return self::build($mesh, self::placements([$from, $to], $count, $spacing, $fit, $centered), $align, $local);
    }

    /**
     * Run along a polyline.
     *
     * @param list<Vec3> $path
     * @param (callable(int, array{position: Vec3, tangent: Vec3}): ?Mat4)|null $local
     */
    public static function alongPath(
        MeshData $mesh,
        array $path,
        ?int $count = null,
        ?float $spacing = null,
        bool $fit = false,
        bool $centered = false,
        bool $align = true,
        ?callable $local = null,
    ): MeshData {
        return self::build($mesh, self::placements($path, $count, $spacing, $fit, $centered), $align, $local);
    }
}
