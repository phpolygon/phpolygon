<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Strand hair (shader plan 10.2): $strands strands grown out of a scalp mesh, each a
 * chain of $segments crossed ribbons (two perpendicular quads per segment, so a strand
 * has width from every side) that bends down under $gravity, curls by $curl turns and
 * tapers to the tip. The normal attribute carries the strand direction - draw it with
 * a Material whose $hair is true, which shades a strand like a thin cylinder (light by
 * the sine to the strand, the highlight in a band along it). UV: u across, v from root
 * (0) to tip (1). Roots are spread over the scalp by triangle area, deterministic per
 * $seed.
 */
final class HairMesh
{
    public static function generate(
        MeshData $scalp,
        int $strands,
        float $length,
        float $width,
        float $curl = 0.0,
        int $segments = 6,
        float $gravity = 0.6,
        int $seed = 1,
    ): MeshData {
        $tris = intdiv(count($scalp->indices), 3);
        if ($tris === 0 || $strands < 1 || $segments < 1 || $length <= 0.0) {
            return new MeshData([], [], [], []);
        }
        // Cumulative triangle areas: roots spread evenly over the surface.
        $cum = [];
        $total = 0.0;
        for ($t = 0; $t < $tris; $t++) {
            [$a, $b, $c] = self::corners($scalp, $t);
            $total += 0.5 * self::len(self::cross(self::sub($b, $a), self::sub($c, $a)));
            $cum[$t] = $total;
        }
        // Local engine instead of the global mt_srand: deterministic per seed and thread-safe.
        // nextInt() on Mt19937 yields the same sequence as mt_rand(), so existing seeds are unchanged.
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        $max = (float) mt_getrandmax();
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        for ($s = 0; $s < $strands; $s++) {
            $r = $rng->nextInt() / $max * $total;
            $lo = 0;
            $hi = $tris - 1;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($cum[$mid] < $r) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            [$a, $b, $c, $na, $nb, $nc] = self::corners($scalp, $lo, true);
            $u = $rng->nextInt() / $max;
            $v = $rng->nextInt() / $max;
            if ($u + $v > 1.0) {
                $u = 1.0 - $u;
                $v = 1.0 - $v;
            }
            $w = 1.0 - $u - $v;
            $p = [$a[0] * $w + $b[0] * $u + $c[0] * $v, $a[1] * $w + $b[1] * $u + $c[1] * $v, $a[2] * $w + $b[2] * $u + $c[2] * $v];
            $n = self::norm([$na[0] * $w + $nb[0] * $u + $nc[0] * $v, $na[1] * $w + $nb[1] * $u + $nc[1] * $v, $na[2] * $w + $nb[2] * $u + $nc[2] * $v]);
            $phase = $rng->nextInt() / $max * 2.0 * M_PI;
            $strandLen = $length * (0.8 + 0.4 * $rng->nextInt() / $max);
            // A side axis for the curl, perpendicular to the root normal.
            $side = self::norm(self::cross($n, abs($n[1]) < 0.9 ? [0.0, 1.0, 0.0] : [1.0, 0.0, 0.0]));
            $side2 = self::cross($n, $side);
            $points = [$p];
            $dirs = [];
            for ($k = 1; $k <= $segments; $k++) {
                $f = $k / $segments;
                $ang = $phase + $curl * 2.0 * M_PI * $f;
                $dir = self::norm([
                    $n[0] * (1.0 - $f * $gravity) + ($side[0] * cos($ang) + $side2[0] * sin($ang)) * $curl * 0.5,
                    $n[1] * (1.0 - $f * $gravity) - $gravity * $f * 1.5 + ($side[1] * cos($ang) + $side2[1] * sin($ang)) * $curl * 0.5,
                    $n[2] * (1.0 - $f * $gravity) + ($side[2] * cos($ang) + $side2[2] * sin($ang)) * $curl * 0.5,
                ]);
                $dirs[] = $dir;
                $prev = $points[$k - 1];
                $points[] = [$prev[0] + $dir[0] * $strandLen / $segments, $prev[1] + $dir[1] * $strandLen / $segments, $prev[2] + $dir[2] * $strandLen / $segments];
            }
            $dirs[] = $dirs[$segments - 1];
            // Two crossed ribbons along the strand.
            foreach ([0, 1] as $ribbon) {
                $base = intdiv(count($vertices), 3);
                for ($k = 0; $k <= $segments; $k++) {
                    $d = $dirs[$k];
                    $ref = $ribbon === 0 ? $side : $side2;
                    $across = self::norm(self::cross($d, self::cross($ref, $d)));
                    $hw = 0.5 * $width * (1.0 - 0.85 * $k / $segments);
                    $q = $points[$k];
                    array_push($vertices, $q[0] - $across[0] * $hw, $q[1] - $across[1] * $hw, $q[2] - $across[2] * $hw);
                    array_push($vertices, $q[0] + $across[0] * $hw, $q[1] + $across[1] * $hw, $q[2] + $across[2] * $hw);
                    array_push($normals, $d[0], $d[1], $d[2], $d[0], $d[1], $d[2]);
                    array_push($uvs, 0.0, $k / $segments, 1.0, $k / $segments);
                }
                for ($k = 0; $k < $segments; $k++) {
                    $i = $base + $k * 2;
                    array_push($indices, $i, $i + 1, $i + 3, $i, $i + 3, $i + 2);
                }
            }
        }
        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    /** @return array<int, list<float>> */
    private static function corners(MeshData $m, int $t, bool $withNormals = false): array
    {
        $out = [];
        $ids = [(int) $m->indices[$t * 3], (int) $m->indices[$t * 3 + 1], (int) $m->indices[$t * 3 + 2]];
        foreach ($ids as $i) {
            $out[] = [(float) $m->vertices[$i * 3], (float) $m->vertices[$i * 3 + 1], (float) $m->vertices[$i * 3 + 2]];
        }
        if ($withNormals) {
            foreach ($ids as $i) {
                $out[] = [(float) ($m->normals[$i * 3] ?? 0.0), (float) ($m->normals[$i * 3 + 1] ?? 1.0), (float) ($m->normals[$i * 3 + 2] ?? 0.0)];
            }
        }
        return $out;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float>
     */
    private static function sub(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float>
     */
    private static function cross(array $a, array $b): array
    {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }

    /** @param list<float> $a */
    private static function len(array $a): float
    {
        return sqrt($a[0] * $a[0] + $a[1] * $a[1] + $a[2] * $a[2]);
    }

    /**
     * @param list<float> $a
     * @return list<float>
     */
    private static function norm(array $a): array
    {
        $l = self::len($a);
        return $l > 1e-12 ? [$a[0] / $l, $a[1] / $l, $a[2] / $l] : [0.0, 1.0, 0.0];
    }
}
