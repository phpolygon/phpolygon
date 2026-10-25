<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Geometry\MeshData;

/**
 * A tuft of grass blades as one mesh, the instance unit of {@see \PHPolygon\System\GrassSystem}.
 *
 * Every blade is a tapered strip that leans away from the tuft centre and curves
 * further toward its tip. The roots sit on y = 0 around the origin, the tip is a
 * single vertex. The vertex data carries what the grass vertex/fragment stage
 * needs, so no per-instance or per-vertex extra attributes exist:
 *
 * - `uv.x` = a random value of the blade in [0, 1) (colour variation, wind phase),
 * - `uv.y` = the position along the blade, 0 at the root, 1 at the tip (bend
 *   weight, colour gradient, root darkening).
 *
 * The blade layout (roots, heights, facing, lean) depends only on the seed and
 * the blade index, never on the segment count - {@see GrassBladeShape::withSegments()}
 * gives a coarser LOD whose blades stand exactly where the fine ones do.
 *
 * Normals lean toward world up by {@see GrassBladeShape::$upBias}; triangles wind
 * counter-clockwise around them. Pair with a two-sided material (the back of a
 * blade is lit like its front).
 */
final class GrassTuftMesh
{
    public static function generate(GrassBladeShape $shape, int $seed = 1): MeshData
    {
        $segments = max(1, $shape->segments);
        $fold = $shape->fold && $segments >= 2;
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        $blades = max(1, $shape->blades);
        for ($b = 0; $b < $blades; $b++) {
            $r = static fn(int $k): float => PlantGenerator::rnd($seed, $b * 16 + $k);
            // Root on a disc (sqrt for an even spread), facing and lean.
            $ra = $r(0) * 2.0 * M_PI;
            $rr = sqrt($r(1)) * $shape->tuftRadius;
            $rx = cos($ra) * $rr;
            $rz = sin($ra) * $rr;
            $height = $shape->heightMin + ($shape->heightMax - $shape->heightMin) * $r(2);
            // Lean outward from the centre, with some spread.
            $leanYaw = ($rr > 1e-5 ? atan2($rz, $rx) : $ra) + ($r(3) - 0.5) * 1.6;
            $lx = cos($leanYaw);
            $lz = sin($leanYaw);
            $leanAngle = $shape->lean * (0.35 + 0.65 * $r(4));
            $curve = $shape->curvature * (0.5 + $r(5));
            // The blade face turns a little away from the lean plane.
            $twist = ($r(6) - 0.5) * 0.9;
            $wx = -$lz * cos($twist) + $lx * sin($twist);
            $wz = $lx * cos($twist) + $lz * sin($twist);
            $width = $shape->width * (0.75 + 0.5 * $r(7));
            $rand = $r(9) * 0.999;

            $tanLean = tan($leanAngle);
            $centre = static function (float $t) use ($rx, $rz, $lx, $lz, $height, $tanLean, $curve): array {
                $off = $height * ($tanLean * $t * 0.6 + $curve * $t * $t);
                $y = $height * $t * (1.0 - 0.18 * $curve * $t * $t);
                return [$rx + $lx * $off, $y, $rz + $lz * $off];
            };
            // Face normal of the blade: perpendicular to its width and its
            // tangent, on the side facing up and away from the lean.
            $faceNormal = static function (float $t) use ($centre, $wx, $wz, $lx, $lz): array {
                $t0 = max(0.0, $t - 0.02);
                $t1 = min(1.0, $t + 0.02);
                $a = $centre($t0);
                $c = $centre($t1);
                $tx = $c[0] - $a[0];
                $ty = $c[1] - $a[1];
                $tz = $c[2] - $a[2];
                // n = w x tangent
                $nx = 0.0 * $tz - $wz * $ty;
                $ny = $wz * $tx - $wx * $tz;
                $nz = $wx * $ty - 0.0 * $tx;
                if ($nx * $lx + $nz * $lz > 0.0 || ($ny < 0.0 && abs($nx * $lx + $nz * $lz) < 1e-6)) {
                    $nx = -$nx;
                    $ny = -$ny;
                    $nz = -$nz;
                }
                $l = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                return $l > 1e-9 ? [$nx / $l, $ny / $l, $nz / $l] : [0.0, 1.0, 0.0];
            };

            /** @var list<list<int>> $rows vertex indices per ring: [left, (mid,) right] or [tip] */
            $rows = [];
            for ($s = 0; $s <= $segments; $s++) {
                $t = $s / $segments;
                $c = $centre($t);
                $n = $faceNormal($t);
                $row = [];
                if ($s === $segments) {
                    $row[] = self::vertex($vertices, $normals, $uvs, $c, self::leanUp($n, $shape->upBias), $rand, $t);
                    $rows[] = $row;
                    continue;
                }
                $hw = 0.5 * $width * (1.0 - $t ** max(0.2, $shape->taper));
                if ($fold) {
                    // Midrib pushed back along the normal: a shallow channel (the
                    // upper face of a blade is concave), its halves facing inward.
                    $depth = $hw * 0.45;
                    $mid = [$c[0] - $n[0] * $depth, $c[1] - $n[1] * $depth, $c[2] - $n[2] * $depth];
                    $nl = [$n[0] + $wx * 0.35, $n[1], $n[2] + $wz * 0.35];
                    $nr = [$n[0] - $wx * 0.35, $n[1], $n[2] - $wz * 0.35];
                    $row[] = self::vertex($vertices, $normals, $uvs, [$c[0] - $wx * $hw, $c[1], $c[2] - $wz * $hw], self::leanUp(self::norm($nl), $shape->upBias), $rand, $t);
                    $row[] = self::vertex($vertices, $normals, $uvs, $mid, self::leanUp($n, $shape->upBias), $rand, $t);
                    $row[] = self::vertex($vertices, $normals, $uvs, [$c[0] + $wx * $hw, $c[1], $c[2] + $wz * $hw], self::leanUp(self::norm($nr), $shape->upBias), $rand, $t);
                } else {
                    $row[] = self::vertex($vertices, $normals, $uvs, [$c[0] - $wx * $hw, $c[1], $c[2] - $wz * $hw], self::leanUp($n, $shape->upBias), $rand, $t);
                    $row[] = self::vertex($vertices, $normals, $uvs, [$c[0] + $wx * $hw, $c[1], $c[2] + $wz * $hw], self::leanUp($n, $shape->upBias), $rand, $t);
                }
                $rows[] = $row;
            }
            for ($s = 0; $s < $segments; $s++) {
                $lo = $rows[$s];
                $hi = $rows[$s + 1];
                $strips = count($lo) - 1;
                for ($k = 0; $k < $strips; $k++) {
                    if (count($hi) === 1) {
                        self::triangle($indices, $vertices, $normals, $lo[$k], $lo[$k + 1], $hi[0]);
                    } else {
                        self::triangle($indices, $vertices, $normals, $lo[$k], $lo[$k + 1], $hi[$k + 1]);
                        self::triangle($indices, $vertices, $normals, $lo[$k], $hi[$k + 1], $hi[$k]);
                    }
                }
            }
        }
        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    /**
     * @param list<float> $vertices
     * @param list<float> $normals
     * @param list<float> $uvs
     * @param array{0: float, 1: float, 2: float} $p
     * @param array{0: float, 1: float, 2: float} $n
     */
    private static function vertex(array &$vertices, array &$normals, array &$uvs, array $p, array $n, float $rand, float $t): int
    {
        $index = intdiv(count($vertices), 3);
        array_push($vertices, $p[0], $p[1], $p[2]);
        array_push($normals, $n[0], $n[1], $n[2]);
        array_push($uvs, $rand, $t);
        return $index;
    }

    /**
     * A triangle wound counter-clockwise around the averaged vertex normal.
     *
     * @param list<int> $indices
     * @param list<float> $vertices
     * @param list<float> $normals
     */
    private static function triangle(array &$indices, array $vertices, array $normals, int $a, int $b, int $c): void
    {
        $ax = $vertices[$a * 3];
        $ay = $vertices[$a * 3 + 1];
        $az = $vertices[$a * 3 + 2];
        $e1 = [$vertices[$b * 3] - $ax, $vertices[$b * 3 + 1] - $ay, $vertices[$b * 3 + 2] - $az];
        $e2 = [$vertices[$c * 3] - $ax, $vertices[$c * 3 + 1] - $ay, $vertices[$c * 3 + 2] - $az];
        $cx = $e1[1] * $e2[2] - $e1[2] * $e2[1];
        $cy = $e1[2] * $e2[0] - $e1[0] * $e2[2];
        $cz = $e1[0] * $e2[1] - $e1[1] * $e2[0];
        $nx = $normals[$a * 3] + $normals[$b * 3] + $normals[$c * 3];
        $ny = $normals[$a * 3 + 1] + $normals[$b * 3 + 1] + $normals[$c * 3 + 1];
        $nz = $normals[$a * 3 + 2] + $normals[$b * 3 + 2] + $normals[$c * 3 + 2];
        if ($cx * $nx + $cy * $ny + $cz * $nz < 0.0) {
            array_push($indices, $a, $c, $b);
        } else {
            array_push($indices, $a, $b, $c);
        }
    }

    /**
     * A normal blended toward world up by `$upBias` (0..1), renormalised.
     *
     * @param array{0: float, 1: float, 2: float} $n
     * @return array{0: float, 1: float, 2: float}
     */
    private static function leanUp(array $n, float $upBias): array
    {
        $u = max(0.0, min(1.0, $upBias));
        $x = $n[0] * (1.0 - $u);
        $y = $n[1] * (1.0 - $u) + $u;
        $z = $n[2] * (1.0 - $u);
        $l = sqrt($x * $x + $y * $y + $z * $z);
        return $l > 1e-9 ? [$x / $l, $y / $l, $z / $l] : [0.0, 1.0, 0.0];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $v
     * @return array{0: float, 1: float, 2: float}
     */
    private static function norm(array $v): array
    {
        $l = sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);
        return $l > 1e-9 ? [$v[0] / $l, $v[1] / $l, $v[2] / $l] : [0.0, 1.0, 0.0];
    }
}
