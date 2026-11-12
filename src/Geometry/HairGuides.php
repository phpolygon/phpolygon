<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Hair guide curves grown out of a scalp mesh: roots spread over the scalp by triangle
 * area, each guide leaving along the surface normal, being combed toward a flow field
 * (parting, whorl, any callable) and falling under gravity. Pure and deterministic per
 * $seed (local Randomizer, no global state): safe inside a worker thread.
 *
 * Feed the result to {@see HairCardMesh}. Game-agnostic: lengths, counts and flow are
 * parameters.
 */
final class HairGuides
{
    /**
     * @param ?callable(float, float, float, float, float, float): array{0: float, 1: float, 2: float} $flow
     *        (px, py, pz, nx, ny, nz) = root position and scalp normal => the direction the hair is combed toward; null = none.
     * @param int   $points       Points per guide including root and tip (>= 2).
     * @param float $combing      0..1: how far the guide bends from the normal toward the flow direction.
     * @param float $gravity      Pull toward -Y along the guide (0 = none).
     * @param float $lengthJitter 0..1: per-guide length varies by +-this fraction.
     * @param float $waves        Number of waves along a guide (0 = straight); $waviness is their sideways amplitude per unit length.
     * @return list<HairGuide>
     */
    public static function grow(
        MeshData $scalp,
        int $count,
        float $length,
        int $points = 6,
        float $gravity = 0.6,
        float $combing = 0.6,
        ?callable $flow = null,
        float $lengthJitter = 0.2,
        float $waves = 0.0,
        float $waviness = 0.0,
        int $seed = 1,
    ): array {
        $tris = intdiv(count($scalp->indices), 3);
        if ($tris === 0 || $count < 1 || $points < 2 || $length <= 0.0) {
            return [];
        }
        $cum = [];
        $total = 0.0;
        for ($t = 0; $t < $tris; $t++) {
            $a = self::vertex($scalp, (int) $scalp->indices[$t * 3]);
            $b = self::vertex($scalp, (int) $scalp->indices[$t * 3 + 1]);
            $c = self::vertex($scalp, (int) $scalp->indices[$t * 3 + 2]);
            $total += 0.5 * self::len(self::cross(self::sub($b, $a), self::sub($c, $a)));
            $cum[$t] = $total;
        }
        if ($total <= 0.0) {
            return [];
        }
        $rng = new Randomizer(new Mt19937($seed));
        $max = (float) mt_getrandmax();
        $guides = [];
        for ($g = 0; $g < $count; $g++) {
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
            $ia = (int) $scalp->indices[$lo * 3];
            $ib = (int) $scalp->indices[$lo * 3 + 1];
            $ic = (int) $scalp->indices[$lo * 3 + 2];
            $u = $rng->nextInt() / $max;
            $v = $rng->nextInt() / $max;
            if ($u + $v > 1.0) {
                $u = 1.0 - $u;
                $v = 1.0 - $v;
            }
            $w = 1.0 - $u - $v;
            $p = self::mix3(self::vertex($scalp, $ia), self::vertex($scalp, $ib), self::vertex($scalp, $ic), $w, $u, $v);
            $n = self::norm(self::mix3(self::normal($scalp, $ia), self::normal($scalp, $ib), self::normal($scalp, $ic), $w, $u, $v));
            $phase = $rng->nextInt() / $max * 2.0 * M_PI;
            $guideLen = $length * (1.0 + $lengthJitter * (2.0 * $rng->nextInt() / $max - 1.0));

            $flowDir = null;
            if ($flow !== null && $combing > 0.0) {
                $flowDir = self::norm($flow($p[0], $p[1], $p[2], $n[0], $n[1], $n[2]));
                // Keep only the part of the flow that lies in the scalp tangent plane.
                $dn = self::dot($flowDir, $n);
                $flowDir = self::norm([$flowDir[0] - $n[0] * $dn, $flowDir[1] - $n[1] * $dn, $flowDir[2] - $n[2] * $dn]);
            }
            $side = self::norm(self::cross($n, abs($n[1]) < 0.9 ? [0.0, 1.0, 0.0] : [1.0, 0.0, 0.0]));
            $step = $guideLen / ($points - 1);
            $curve = [$p];
            for ($k = 1; $k < $points; $k++) {
                $f = $k / ($points - 1);
                $dir = $n;
                if ($flowDir !== null) {
                    $c = $combing * min(1.0, $f * 2.0);
                    $dir = self::norm([
                        $n[0] * (1.0 - $c) + $flowDir[0] * $c,
                        $n[1] * (1.0 - $c) + $flowDir[1] * $c,
                        $n[2] * (1.0 - $c) + $flowDir[2] * $c,
                    ]);
                }
                $dir = self::norm([
                    $dir[0] * (1.0 - $f * $gravity),
                    $dir[1] * (1.0 - $f * $gravity) - $gravity * $f * 1.5,
                    $dir[2] * (1.0 - $f * $gravity),
                ]);
                if ($waves > 0.0 && $waviness > 0.0) {
                    $s = sin($phase + $f * $waves * 2.0 * M_PI) * $waviness;
                    $dir = self::norm([$dir[0] + $side[0] * $s, $dir[1] + $side[1] * $s, $dir[2] + $side[2] * $s]);
                }
                $prev = $curve[$k - 1];
                $curve[] = [$prev[0] + $dir[0] * $step, $prev[1] + $dir[1] * $step, $prev[2] + $dir[2] * $step];
            }
            $guides[] = new HairGuide($curve, $n);
        }

        return $guides;
    }

    /**
     * Flow field that combs hair away from a parting line: the line runs through $origin
     * along $lineDir; hair on each side is combed to that side (a side parting, centre
     * parting or a shaved line, depending on where the caller puts it).
     *
     * @param array{0: float, 1: float, 2: float} $origin
     * @param array{0: float, 1: float, 2: float} $lineDir
     * @return \Closure(float, float, float, float, float, float): array{0: float, 1: float, 2: float}
     */
    public static function partingFlow(array $origin, array $lineDir): \Closure
    {
        $across = self::norm(self::cross(self::norm($lineDir), [0.0, 1.0, 0.0]));

        return static function (float $px, float $py, float $pz, float $nx, float $ny, float $nz) use ($origin, $across): array {
            $side = self::dot(self::sub([$px, $py, $pz], $origin), $across) >= 0.0 ? 1.0 : -1.0;

            return [$across[0] * $side, $across[1] * $side, $across[2] * $side];
        };
    }

    /**
     * Flow field that swirls hair around a vertical axis through $center (a crown whorl):
     * the direction is tangential around the axis, $outward blends in a radial push.
     *
     * @param array{0: float, 1: float, 2: float} $center
     * @return \Closure(float, float, float, float, float, float): array{0: float, 1: float, 2: float}
     */
    public static function whorlFlow(array $center, float $outward = 0.3): \Closure
    {
        return static function (float $px, float $py, float $pz, float $nx, float $ny, float $nz) use ($center, $outward): array {
            $rx = $px - $center[0];
            $rz = $pz - $center[2];
            $l = sqrt($rx * $rx + $rz * $rz);
            if ($l < 1e-9) {
                return [1.0, 0.0, 0.0];
            }
            $rx /= $l;
            $rz /= $l;

            return [-$rz + $rx * $outward, 0.0, $rx + $rz * $outward];
        };
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function vertex(MeshData $m, int $i): array
    {
        return [(float) $m->vertices[$i * 3], (float) $m->vertices[$i * 3 + 1], (float) $m->vertices[$i * 3 + 2]];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function normal(MeshData $m, int $i): array
    {
        return [(float) ($m->normals[$i * 3] ?? 0.0), (float) ($m->normals[$i * 3 + 1] ?? 1.0), (float) ($m->normals[$i * 3 + 2] ?? 0.0)];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @param array{0: float, 1: float, 2: float} $c
     * @return array{0: float, 1: float, 2: float}
     */
    private static function mix3(array $a, array $b, array $c, float $wa, float $wb, float $wc): array
    {
        return [
            $a[0] * $wa + $b[0] * $wb + $c[0] * $wc,
            $a[1] * $wa + $b[1] * $wb + $c[1] * $wc,
            $a[2] * $wa + $b[2] * $wb + $c[2] * $wc,
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
     */
    private static function sub(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
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

    /** @param array{0: float, 1: float, 2: float} $a */
    private static function len(array $a): float
    {
        return sqrt($a[0] * $a[0] + $a[1] * $a[1] + $a[2] * $a[2]);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @return array{0: float, 1: float, 2: float}
     */
    private static function norm(array $a): array
    {
        $l = self::len($a);

        return $l > 1e-12 ? [$a[0] / $l, $a[1] / $l, $a[2] / $l] : [0.0, 1.0, 0.0];
    }
}
