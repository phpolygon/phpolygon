<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * A rotated box collider as a solid: centre, three unit axes, half extents. Unlike
 * its 12 triangles it has an inside, so a capsule that a long tick carried past a
 * face still comes out the shortest way instead of walking on through the box.
 */
final class OrientedBox
{
    /** World-space hull, for the cheap rejection every tick. */
    public readonly Vec3 $min;
    public readonly Vec3 $max;

    public function __construct(
        public readonly float $cx,
        public readonly float $cy,
        public readonly float $cz,
        /** @var array{float, float, float} */
        public readonly array $axisX,
        /** @var array{float, float, float} */
        public readonly array $axisY,
        /** @var array{float, float, float} */
        public readonly array $axisZ,
        public readonly float $hx,
        public readonly float $hy,
        public readonly float $hz,
    ) {
        $ex = abs($axisX[0]) * $hx + abs($axisY[0]) * $hy + abs($axisZ[0]) * $hz;
        $ey = abs($axisX[1]) * $hx + abs($axisY[1]) * $hy + abs($axisZ[1]) * $hz;
        $ez = abs($axisX[2]) * $hx + abs($axisY[2]) * $hy + abs($axisZ[2]) * $hz;
        $this->min = new Vec3($cx - $ex, $cy - $ey, $cz - $ez);
        $this->max = new Vec3($cx + $ex, $cy + $ey, $cz + $ez);
    }

    public static function fromCollider(BoxCollider3D $box, Mat4 $world): self
    {
        $m = $world->toArray();
        $center = $world->transformPoint($box->offset);
        $lx = sqrt($m[0] * $m[0] + $m[1] * $m[1] + $m[2] * $m[2]);
        $ly = sqrt($m[4] * $m[4] + $m[5] * $m[5] + $m[6] * $m[6]);
        $lz = sqrt($m[8] * $m[8] + $m[9] * $m[9] + $m[10] * $m[10]);

        return new self(
            $center->x,
            $center->y,
            $center->z,
            [$m[0] / $lx, $m[1] / $lx, $m[2] / $lx],
            [$m[4] / $ly, $m[5] / $ly, $m[6] / $ly],
            [$m[8] / $lz, $m[9] / $lz, $m[10] / $lz],
            $box->size->x * 0.5 * $lx,
            $box->size->y * 0.5 * $ly,
            $box->size->z * 0.5 * $lz,
        );
    }

    /**
     * The push that takes a capsule (segment a → b, radius) out of the box, or null.
     * Outside, along the true distance to the box. With the segment inside, along
     * the box axis that frees it soonest.
     */
    public function capsulePush(Vec3 $a, Vec3 $b, float $radius): ?Vec3
    {
        if (max($a->x, $b->x) + $radius <= $this->min->x || min($a->x, $b->x) - $radius >= $this->max->x
            || max($a->y, $b->y) + $radius <= $this->min->y || min($a->y, $b->y) - $radius >= $this->max->y
            || max($a->z, $b->z) + $radius <= $this->min->z || min($a->z, $b->z) - $radius >= $this->max->z
        ) {
            return null;
        }

        $from = $this->toLocal($a->x, $a->y, $a->z);
        $to = $this->toLocal($b->x, $b->y, $b->z);
        $half = [$this->hx, $this->hy, $this->hz];
        $delta = [$to[0] - $from[0], $to[1] - $from[1], $to[2] - $from[2]];

        // Squared distance along the segment is piecewise quadratic, with breaks
        // where a coordinate crosses a face.
        $breaks = [0.0, 1.0];
        for ($i = 0; $i < 3; $i++) {
            if (abs($delta[$i]) > 1e-12) {
                foreach ([-$half[$i], $half[$i]] as $face) {
                    $t = ($face - $from[$i]) / $delta[$i];
                    if ($t > 0.0 && $t < 1.0) {
                        $breaks[] = $t;
                    }
                }
            }
        }
        sort($breaks);

        $bestT = 0.0;
        $bestSq = INF;
        for ($k = 0, $n = count($breaks) - 1; $k < $n; $k++) {
            $t0 = $breaks[$k];
            $t1 = $breaks[$k + 1];
            $mid = ($t0 + $t1) * 0.5;
            $numerator = 0.0;
            $denominator = 0.0;
            for ($i = 0; $i < 3; $i++) {
                $p = $from[$i] + $mid * $delta[$i];
                if ($p > $half[$i] || $p < -$half[$i]) {
                    $face = $p > 0.0 ? $half[$i] : -$half[$i];
                    $numerator += $delta[$i] * ($face - $from[$i]);
                    $denominator += $delta[$i] * $delta[$i];
                }
            }
            $t = $denominator > 1e-18 ? max($t0, min($t1, $numerator / $denominator)) : $t0;
            $sq = $this->outsideSq($from, $delta, $half, $t);
            if ($sq < $bestSq) {
                $bestSq = $sq;
                $bestT = $t;
            }
            if ($bestSq <= 0.0) {
                break;
            }
        }

        if ($bestSq >= $radius * $radius) {
            return null;
        }

        if ($bestSq > 1e-18) {
            $outside = [0.0, 0.0, 0.0];
            for ($i = 0; $i < 3; $i++) {
                $p = $from[$i] + $bestT * $delta[$i];
                if ($p > $half[$i]) {
                    $outside[$i] = $p - $half[$i];
                } elseif ($p < -$half[$i]) {
                    $outside[$i] = $p + $half[$i];
                }
            }
            $distance = sqrt($bestSq);
            $scale = ($radius - $distance) / $distance;

            return $this->toWorldDirection($outside[0] * $scale, $outside[1] * $scale, $outside[2] * $scale);
        }

        // The segment is in the box: free all of it along the nearest way.
        $bestPush = INF;
        $bestAxis = 0;
        for ($i = 0; $i < 3; $i++) {
            $low = min($from[$i], $to[$i]);
            $high = max($from[$i], $to[$i]);
            $up = $half[$i] - $low + $radius;
            $down = $high + $half[$i] + $radius;
            if ($up < abs($bestPush)) {
                $bestPush = $up;
                $bestAxis = $i;
            }
            if ($down < abs($bestPush)) {
                $bestPush = -$down;
                $bestAxis = $i;
            }
        }
        $push = [0.0, 0.0, 0.0];
        $push[$bestAxis] = $bestPush;

        return $this->toWorldDirection($push[0], $push[1], $push[2]);
    }

    /** Height of the highest walkable face over (x, z), or null off its footprint. */
    public function surfaceAt(float $x, float $z): ?float
    {
        $best = null;
        foreach ($this->faces() as [$axis, $half, $u, $uHalf, $v, $vHalf]) {
            if (abs($axis[1]) < 1e-6) {
                continue;
            }
            $sign = $axis[1] > 0.0 ? 1.0 : -1.0;
            $y = $this->planeY($axis, $sign * $half, $x, $z);
            $dx = $x - $this->cx;
            $dy = $y - $this->cy;
            $dz = $z - $this->cz;
            $inU = $dx * $u[0] + $dy * $u[1] + $dz * $u[2];
            $inV = $dx * $v[0] + $dy * $v[1] + $dz * $v[2];
            if (abs($inU) <= $uHalf + 1e-9 && abs($inV) <= $vHalf + 1e-9 && ($best === null || $y > $best)) {
                $best = $y;
            }
        }

        return $best;
    }

    /**
     * Height of the top face at the point of it nearest to (x, z): where a capsule
     * touching the side of a tilted board would step onto it, not the top of the hull.
     */
    public function topNear(float $x, float $z): float
    {
        $best = null;
        $bestY = 0.0;
        foreach ($this->faces() as $face) {
            if ($best === null || abs($face[0][1]) > abs($bestY)) {
                $best = $face;
                $bestY = $face[0][1];
            }
        }
        if ($best === null || abs($bestY) < 1e-6) {
            return $this->max->y;
        }

        [$axis, $half, $u, $uHalf, $v, $vHalf] = $best;
        $sign = $axis[1] > 0.0 ? 1.0 : -1.0;
        $y = $this->planeY($axis, $sign * $half, $x, $z);
        $dx = $x - $this->cx;
        $dy = $y - $this->cy;
        $dz = $z - $this->cz;
        $inU = max(-$uHalf, min($uHalf, $dx * $u[0] + $dy * $u[1] + $dz * $u[2]));
        $inV = max(-$vHalf, min($vHalf, $dx * $v[0] + $dy * $v[1] + $dz * $v[2]));

        return $this->cy + $axis[1] * $sign * $half + $u[1] * $inU + $v[1] * $inV;
    }

    /**
     * @return list<array{array{float, float, float}, float, array{float, float, float}, float, array{float, float, float}, float}>
     */
    private function faces(): array
    {
        return [
            [$this->axisY, $this->hy, $this->axisX, $this->hx, $this->axisZ, $this->hz],
            [$this->axisX, $this->hx, $this->axisY, $this->hy, $this->axisZ, $this->hz],
            [$this->axisZ, $this->hz, $this->axisX, $this->hx, $this->axisY, $this->hy],
        ];
    }

    /**
     * Height over (x, z) of the plane at signed distance $offset from the centre along $axis.
     *
     * @param array{float, float, float} $axis
     */
    private function planeY(array $axis, float $offset, float $x, float $z): float
    {
        return $this->cy + ($offset - $axis[0] * ($x - $this->cx) - $axis[2] * ($z - $this->cz)) / $axis[1];
    }

    /** @return array{float, float, float} */
    private function toLocal(float $x, float $y, float $z): array
    {
        $dx = $x - $this->cx;
        $dy = $y - $this->cy;
        $dz = $z - $this->cz;

        return [
            $dx * $this->axisX[0] + $dy * $this->axisX[1] + $dz * $this->axisX[2],
            $dx * $this->axisY[0] + $dy * $this->axisY[1] + $dz * $this->axisY[2],
            $dx * $this->axisZ[0] + $dy * $this->axisZ[1] + $dz * $this->axisZ[2],
        ];
    }

    private function toWorldDirection(float $x, float $y, float $z): Vec3
    {
        return new Vec3(
            $x * $this->axisX[0] + $y * $this->axisY[0] + $z * $this->axisZ[0],
            $x * $this->axisX[1] + $y * $this->axisY[1] + $z * $this->axisZ[1],
            $x * $this->axisX[2] + $y * $this->axisY[2] + $z * $this->axisZ[2],
        );
    }

    /**
     * Squared distance from the segment point at $t to the box.
     *
     * @param array{float, float, float} $from
     * @param array{float, float, float} $delta
     * @param array{float, float, float} $half
     */
    private function outsideSq(array $from, array $delta, array $half, float $t): float
    {
        $sq = 0.0;
        for ($i = 0; $i < 3; $i++) {
            $p = $from[$i] + $t * $delta[$i];
            if ($p > $half[$i]) {
                $sq += ($p - $half[$i]) ** 2;
            } elseif ($p < -$half[$i]) {
                $sq += ($p + $half[$i]) ** 2;
            }
        }

        return $sq;
    }
}
