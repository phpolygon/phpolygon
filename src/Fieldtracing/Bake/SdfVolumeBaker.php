<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Bake;

use PHPolygon\Fieldtracing\Sdf\SdfPrimitive;
use PHPolygon\Fieldtracing\Volume\SdfVolume;
use PHPolygon\Math\Vec3;

/**
 * Bakes an analytic {@see SdfPrimitive} (or composite tree) into a uniform
 * {@see SdfVolume}. This is the preferred SDF source — exact distances sampled
 * from closed-form fields, mirroring the engine's "geometry as maths" ethos.
 *
 * Pure CPU, GPU-free. In production this runs on a worker thread via the
 * SdfBakeSystem (SubsystemInterface) so PHP never touches the GPU hot path; the
 * static API here is the unit of work that system schedules.
 */
final class SdfVolumeBaker
{
    /**
     * Bake within an explicit world-space AABB.
     *
     * @param int $resolution Number of samples along the longest axis (>= 2).
     *                        Other axes get a proportional sample count at the
     *                        same cell spacing.
     */
    public static function bake(SdfPrimitive $sdf, Vec3 $min, Vec3 $max, int $resolution): SdfVolume
    {
        if ($resolution < 2) {
            throw new \InvalidArgumentException('resolution must be >= 2.');
        }

        $ex = max($max->x - $min->x, 1e-6);
        $ey = max($max->y - $min->y, 1e-6);
        $ez = max($max->z - $min->z, 1e-6);
        $longest = max($ex, $ey, $ez);

        $cellSize = $longest / ($resolution - 1);
        $nx = max(2, (int)round($ex / $cellSize) + 1);
        $ny = max(2, (int)round($ey / $cellSize) + 1);
        $nz = max(2, (int)round($ez / $cellSize) + 1);

        $data = [];
        for ($iz = 0; $iz < $nz; $iz++) {
            $z = $min->z + $iz * $cellSize;
            for ($iy = 0; $iy < $ny; $iy++) {
                $y = $min->y + $iy * $cellSize;
                for ($ix = 0; $ix < $nx; $ix++) {
                    $x = $min->x + $ix * $cellSize;
                    $data[] = $sdf->distance(new Vec3($x, $y, $z));
                }
            }
        }

        return new SdfVolume($nx, $ny, $nz, $min, $cellSize, $data);
    }

    /**
     * Bake using the primitive's own AABB plus a padding margin (so the trace
     * has empty space to march through near the surface). Throws if the field
     * is unbounded (e.g. an infinite plane) — supply an explicit extent via
     * {@see bake()} in that case.
     */
    public static function bakeAuto(SdfPrimitive $sdf, int $resolution, float $padding = 0.0): SdfVolume
    {
        $bounds = $sdf->bounds();
        if ($bounds === null) {
            throw new \InvalidArgumentException(
                'Cannot auto-bake an unbounded SDF; pass an explicit AABB to bake().'
            );
        }
        $pad = new Vec3($padding, $padding, $padding);
        return self::bake($sdf, $bounds[0]->sub($pad), $bounds[1]->add($pad), $resolution);
    }
}
