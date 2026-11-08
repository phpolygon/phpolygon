<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A gliding bird for flocks (shader plan 7.2, {@see \PHPolygon\Component\Swarm}): a
 * spindle body, two swept wings raised a little towards the tips (dihedral) and a fanned
 * tail. Low poly, origin at the middle of the body, head towards +Z - the direction the
 * swarm kernel turns its agents to - and the back up (+Y). Wings and tail carry both
 * faces with outward normals, so they light from above and below without a two-sided
 * material; the body is closed.
 */
final class BirdMesh
{
    /**
     * @param float $span     wing tip to wing tip (m)
     * @param float $length   beak to tail tip (m)
     * @param float $dihedral rise of a wing tip per metre of half span
     */
    public static function generate(float $span = 1.2, float $length = 0.45, float $dihedral = 0.18): MeshData
    {
        if ($span <= 0.0 || $length <= 0.0) {
            throw new \InvalidArgumentException('BirdMesh: span and length must be positive');
        }
        $out = ['v' => [], 'n' => [], 'uv' => [], 'idx' => []];

        // Body: a spindle of six-sided rings from the beak (+Z) to the rump.
        $r = 0.09 * $length;
        $rings = [[0.5, 0.0], [0.32, 0.55], [0.08, 1.0], [-0.18, 0.8], [-0.36, 0.35]];
        $sides = 6;
        $ringPts = [];
        foreach ($rings as [$zf, $rf]) {
            $pts = [];
            for ($i = 0; $i < $sides; $i++) {
                $a = 2.0 * M_PI * $i / $sides;
                $pts[] = [cos($a) * $r * $rf, sin($a) * $r * $rf * 0.85, $zf * $length];
            }
            $ringPts[] = $pts;
        }
        $beak = [0.0, 0.0, 0.5 * $length];
        $rump = [0.0, 0.0, -0.4 * $length];
        for ($i = 0; $i < $sides; $i++) {
            $j = ($i + 1) % $sides;
            self::tri($out, $beak, $ringPts[1][$i], $ringPts[1][$j], false);
            for ($k = 1; $k < count($ringPts) - 1; $k++) {
                $a = $ringPts[$k][$i]; $b = $ringPts[$k][$j];
                $c = $ringPts[$k + 1][$j]; $d = $ringPts[$k + 1][$i];
                self::tri($out, $a, $d, $c, false);
                self::tri($out, $a, $c, $b, false);
            }
            $last = count($ringPts) - 1;
            self::tri($out, $rump, $ringPts[$last][$j], $ringPts[$last][$i], false);
        }

        // Wings: root chord on the body, a narrower, swept-back tip raised by the dihedral.
        $half = 0.5 * $span;
        $rise = $dihedral * $half;
        foreach ([1.0, -1.0] as $side) {
            $rootFront = [$side * $r * 0.8, $r * 0.3, 0.14 * $length];
            $rootBack = [$side * $r * 0.8, $r * 0.3, -0.12 * $length];
            $elbowFront = [$side * $half * 0.45, $rise * 0.45 + $r * 0.3, 0.12 * $length];
            $elbowBack = [$side * $half * 0.45, $rise * 0.45 + $r * 0.3, -0.16 * $length];
            $tip = [$side * $half, $rise + $r * 0.3, -0.18 * $length];
            if ($side > 0.0) {
                self::tri($out, $rootFront, $elbowFront, $elbowBack, true);
                self::tri($out, $rootFront, $elbowBack, $rootBack, true);
                self::tri($out, $elbowFront, $tip, $elbowBack, true);
            } else {
                self::tri($out, $rootFront, $elbowBack, $elbowFront, true);
                self::tri($out, $rootFront, $rootBack, $elbowBack, true);
                self::tri($out, $elbowFront, $elbowBack, $tip, true);
            }
        }

        // Tail: a fan behind the rump.
        $tailRoot = [0.0, 0.0, -0.32 * $length];
        $tailL = [0.14 * $length, 0.0, -0.5 * $length];
        $tailR = [-0.14 * $length, 0.0, -0.5 * $length];
        self::tri($out, $tailRoot, $tailR, $tailL, true);

        return new MeshData($out['v'], $out['n'], $out['uv'], $out['idx']);
    }

    /**
     * One triangle (both faces with $bothSides), its face normal for all three corners.
     *
     * @param array{v: list<float>, n: list<float>, uv: list<float>, idx: list<int>} $out
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     * @param array{float, float, float} $c
     */
    private static function tri(array &$out, array $a, array $b, array $c, bool $bothSides): void
    {
        $ux = $b[0] - $a[0]; $uy = $b[1] - $a[1]; $uz = $b[2] - $a[2];
        $wx = $c[0] - $a[0]; $wy = $c[1] - $a[1]; $wz = $c[2] - $a[2];
        $nx = $uy * $wz - $uz * $wy;
        $ny = $uz * $wx - $ux * $wz;
        $nz = $ux * $wy - $uy * $wx;
        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
        if ($len < 1e-12) {
            return;
        }
        $nx /= $len; $ny /= $len; $nz /= $len;
        foreach ($bothSides ? [1.0, -1.0] : [1.0] as $s) {
            $base = intdiv(count($out['v']), 3);
            foreach ([$a, $b, $c] as $k => $p) {
                array_push($out['v'], $p[0], $p[1], $p[2]);
                array_push($out['n'], $nx * $s, $ny * $s, $nz * $s);
                array_push($out['uv'], $k === 1 ? 1.0 : 0.0, $k === 2 ? 1.0 : 0.0);
            }
            if ($s > 0.0) {
                array_push($out['idx'], $base, $base + 1, $base + 2);
            } else {
                array_push($out['idx'], $base, $base + 2, $base + 1);
            }
        }
    }
}
