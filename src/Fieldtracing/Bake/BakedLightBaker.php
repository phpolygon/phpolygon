<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Bake;

use PHPolygon\Fieldtracing\Volume\BakedLightVolume;
use PHPolygon\Fieldtracing\Volume\SdfVolume;
use PHPolygon\Math\Vec3;

/**
 * Bakes light from a signed-distance field (shader plan 3.3, "baked lightmaps from
 * the SDF"): for every sample of the field's grid, how much of the sky it sees (soft
 * cone traces over the upper hemisphere) and how much of the sun in one direction (a
 * soft shadow trace). The result is a world-space light volume - no lightmap UVs
 * needed, so imported and procedural meshes alike pick it up - that the renderer
 * samples per pixel instead of tracing every frame.
 *
 * Pure CPU, run at build or load time; the caller caches the result. The cost grows
 * with the sample count and the reach; samples farther from every surface than the
 * reach skip the traces.
 */
final class BakedLightBaker
{
    /**
     * Upper-hemisphere directions for the sky term with their weights: the zenith, a
     * ring at 55 degrees and a ring at 20 degrees elevation. Brighter, higher sky
     * weighs more.
     *
     * @var list<array{0: float, 1: float, 2: float, 3: float}>|null
     */
    private static ?array $skyDirections = null;

    /**
     * @param Vec3  $towardSun   Direction toward the sun (normalised here).
     * @param float $skyReach    How far the sky traces look for occluders, world units.
     * @param float $sunReach    How far the sun trace looks for occluders, world units.
     * @param float $sunSoftness Penumbra sharpness of the sun trace (higher = harder).
     */
    public static function bake(
        SdfVolume $sdf,
        Vec3 $towardSun,
        float $skyReach = 8.0,
        float $sunReach = 40.0,
        float $sunSoftness = 8.0,
    ): BakedLightVolume {
        $len = sqrt($towardSun->x ** 2 + $towardSun->y ** 2 + $towardSun->z ** 2);
        if ($len < 1e-6) {
            throw new \InvalidArgumentException('towardSun must not be zero.');
        }
        $sun = new Vec3($towardSun->x / $len, $towardSun->y / $len, $towardSun->z / $len);
        $nx = $sdf->nx;
        $ny = $sdf->ny;
        $nz = $sdf->nz;
        $cell = $sdf->cellSize;
        $data = $sdf->data;
        $ox = $sdf->origin->x;
        $oy = $sdf->origin->y;
        $oz = $sdf->origin->z;
        $reach = max($skyReach, $sunReach);
        $dirs = self::skyDirections();
        $weightSum = 0.0;
        foreach ($dirs as $d) {
            $weightSum += $d[3];
        }

        // Trilinear distance at a world point; null once the point leaves the grid
        // (open space from there on, as the trace pass treats it).
        $sample = static function (float $x, float $y, float $z) use ($data, $nx, $ny, $nz, $cell, $ox, $oy, $oz): ?float {
            $gx = ($x - $ox) / $cell;
            $gy = ($y - $oy) / $cell;
            $gz = ($z - $oz) / $cell;
            if ($gx < 0.0 || $gy < 0.0 || $gz < 0.0 || $gx > $nx - 1 || $gy > $ny - 1 || $gz > $nz - 1) {
                return null;
            }
            $x0 = min((int) $gx, $nx - 2);
            $y0 = min((int) $gy, $ny - 2);
            $z0 = min((int) $gz, $nz - 2);
            $fx = $gx - $x0;
            $fy = $gy - $y0;
            $fz = $gz - $z0;
            $i = $x0 + $y0 * $nx + $z0 * $nx * $ny;
            $sy = $nx;
            $sz = $nx * $ny;
            $c00 = $data[$i] + ($data[$i + 1] - $data[$i]) * $fx;
            $c10 = $data[$i + $sy] + ($data[$i + $sy + 1] - $data[$i + $sy]) * $fx;
            $c01 = $data[$i + $sz] + ($data[$i + $sz + 1] - $data[$i + $sz]) * $fx;
            $c11 = $data[$i + $sy + $sz] + ($data[$i + $sy + $sz + 1] - $data[$i + $sy + $sz]) * $fx;
            $c0 = $c00 + ($c10 - $c00) * $fy;
            $c1 = $c01 + ($c11 - $c01) * $fy;
            return $c0 + ($c1 - $c0) * $fz;
        };

        // Soft visibility along one ray (Quilez penumbra estimate).
        $visibility = static function (float $px, float $py, float $pz, float $dx, float $dy, float $dz, float $maxT, float $k) use ($sample, $cell): float {
            $res = 1.0;
            $t = $cell * 0.75;
            while ($t < $maxT) {
                $h = $sample($px + $dx * $t, $py + $dy * $t, $pz + $dz * $t);
                if ($h === null) {
                    break;
                }
                if ($h < $cell * 0.05) {
                    return 0.0;
                }
                $res = min($res, $k * $h / $t);
                $t += max($h, $cell * 0.5);
            }
            return max(0.0, min(1.0, $res));
        };

        $count = $nx * $ny * $nz;
        $sky = array_fill(0, $count, 1.0);
        $sunVis = array_fill(0, $count, $sun->y <= 0.0 ? 0.0 : 1.0);
        $inside = [];
        for ($iz = 0; $iz < $nz; $iz++) {
            $pz = $oz + $iz * $cell;
            for ($iy = 0; $iy < $ny; $iy++) {
                $py = $oy + $iy * $cell;
                for ($ix = 0; $ix < $nx; $ix++) {
                    $i = $ix + $iy * $nx + $iz * $nx * $ny;
                    $d = $data[$i];
                    if ($d < 0.0) {
                        $inside[] = $i;
                        continue;
                    }
                    if ($d > $reach) {
                        continue; // nothing near enough to occlude or shade
                    }
                    $px = $ox + $ix * $cell;
                    if ($d <= $skyReach) {
                        $s = 0.0;
                        foreach ($dirs as [$dx, $dy, $dz, $w]) {
                            $s += $w * $visibility($px, $py, $pz, $dx, $dy, $dz, $skyReach, 4.0);
                        }
                        $sky[$i] = $s / $weightSum;
                    }
                    $sunVis[$i] = $sun->y <= 0.0 ? 0.0 : $visibility($px, $py, $pz, $sun->x, $sun->y, $sun->z, $sunReach, $sunSoftness);
                }
            }
        }

        // Samples inside geometry are never seen, but trilinear lookups just off a
        // surface blend them in: give each the mean of its outside neighbours, twice,
        // so surfaces do not darken toward the solid side.
        for ($pass = 0; $pass < 2 && $inside !== []; $pass++) {
            $solid = array_flip($inside);
            $nextInside = [];
            $updates = [];
            foreach ($inside as $i) {
                $ix = $i % $nx;
                $iy = intdiv($i, $nx) % $ny;
                $iz = intdiv($i, $nx * $ny);
                $sumSky = 0.0;
                $sumSun = 0.0;
                $n = 0;
                foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$ax, $ay, $az]) {
                    $jx = $ix + $ax;
                    $jy = $iy + $ay;
                    $jz = $iz + $az;
                    if ($jx < 0 || $jy < 0 || $jz < 0 || $jx >= $nx || $jy >= $ny || $jz >= $nz) {
                        continue;
                    }
                    $j = $jx + $jy * $nx + $jz * $nx * $ny;
                    if (isset($solid[$j])) {
                        continue;
                    }
                    $sumSky += $sky[$j];
                    $sumSun += $sunVis[$j];
                    $n++;
                }
                if ($n > 0) {
                    $updates[$i] = [$sumSky / $n, $sumSun / $n];
                } else {
                    $nextInside[] = $i;
                }
            }
            foreach ($updates as $i => [$s, $u]) {
                $sky[$i] = $s;
                $sunVis[$i] = $u;
            }
            $inside = $nextInside;
        }

        return new BakedLightVolume($nx, $ny, $nz, $sdf->origin, $cell, array_values($sky), array_values($sunVis), $sun);
    }

    /** @return list<array{0: float, 1: float, 2: float, 3: float}> */
    private static function skyDirections(): array
    {
        if (self::$skyDirections !== null) {
            return self::$skyDirections;
        }
        $dirs = [[0.0, 1.0, 0.0, 1.0]];
        foreach ([[55.0, 6, 0.0], [20.0, 6, 30.0]] as [$elevation, $n, $offset]) {
            $e = deg2rad($elevation);
            for ($k = 0; $k < $n; $k++) {
                $a = deg2rad($offset + $k * 360.0 / $n);
                $dirs[] = [cos($e) * cos($a), sin($e), cos($e) * sin($a), sin($e) + 0.2];
            }
        }
        return self::$skyDirections = $dirs;
    }
}
