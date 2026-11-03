<?php

declare(strict_types=1);

namespace PHPolygon\Animation;

/**
 * Quaternion arithmetic on plain [x, y, z, w] arrays, for the per-frame pose path:
 * a figure's dozen joints would otherwise allocate several value objects each per
 * frame. Same formulas as {@see \PHPolygon\Math\Quaternion} (multiply, slerp with the
 * short-path flip and the near-parallel lerp); rotate() uses the expanded form of the
 * sandwich product, equal to rotateVec3() for unit quaternions up to rounding.
 *
 * @internal
 */
final class QuatMath
{
    private function __construct() {}

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $a
     * @param array{0: float, 1: float, 2: float, 3: float} $b
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function mul(array $a, array $b): array
    {
        [$ax, $ay, $az, $aw] = $a;
        [$bx, $by, $bz, $bw] = $b;
        return [
            $aw * $bx + $ax * $bw + $ay * $bz - $az * $by,
            $aw * $by - $ax * $bz + $ay * $bw + $az * $bx,
            $aw * $bz + $ax * $by - $ay * $bx + $az * $bw,
            $aw * $bw - $ax * $bx - $ay * $by - $az * $bz,
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $a
     * @param array{0: float, 1: float, 2: float, 3: float} $b
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function slerp(array $a, array $b, float $t): array
    {
        [$ax, $ay, $az, $aw] = $a;
        [$bx, $by, $bz, $bw] = $b;
        $dot = $ax * $bx + $ay * $by + $az * $bz + $aw * $bw;
        if ($dot < 0.0) {
            $bx = -$bx;
            $by = -$by;
            $bz = -$bz;
            $bw = -$bw;
            $dot = -$dot;
        }
        if ($dot > 0.9995) {
            $x = $ax + $t * ($bx - $ax);
            $y = $ay + $t * ($by - $ay);
            $z = $az + $t * ($bz - $az);
            $w = $aw + $t * ($bw - $aw);
            $len = sqrt($x * $x + $y * $y + $z * $z + $w * $w);
            return $len > 0.0 ? [$x / $len, $y / $len, $z / $len, $w / $len] : [0.0, 0.0, 0.0, 1.0];
        }
        $theta0 = acos($dot);
        $theta = $theta0 * $t;
        $sinTheta0 = sin($theta0);
        $sinTheta = sin($theta);
        $s0 = cos($theta) - $dot * $sinTheta / $sinTheta0;
        $s1 = $sinTheta / $sinTheta0;
        return [$s0 * $ax + $s1 * $bx, $s0 * $ay + $s1 * $by, $s0 * $az + $s1 * $bz, $s0 * $aw + $s1 * $bw];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $q
     * @return array{0: float, 1: float, 2: float}
     */
    public static function rotate(array $q, float $vx, float $vy, float $vz): array
    {
        [$qx, $qy, $qz, $qw] = $q;
        // t = 2 * cross(q.xyz, v); v' = v + w * t + cross(q.xyz, t)
        $tx = 2.0 * ($qy * $vz - $qz * $vy);
        $ty = 2.0 * ($qz * $vx - $qx * $vz);
        $tz = 2.0 * ($qx * $vy - $qy * $vx);
        return [
            $vx + $qw * $tx + ($qy * $tz - $qz * $ty),
            $vy + $qw * $ty + ($qz * $tx - $qx * $tz),
            $vz + $qw * $tz + ($qx * $ty - $qy * $tx),
        ];
    }
}
