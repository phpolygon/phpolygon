<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Volume;

use PHPolygon\Math\Vec3;

/**
 * A baked signed-distance field sampled on a uniform grid.
 *
 * This is the CPU-side representation produced by the bakers and uploaded to the
 * GPU as the trace input — either as a flat array into an SSBO (Tier A) or as a
 * 2D-atlas flipbook of Z-slices (Tier B, the first-shipped path that needs no
 * vio-core change). It is a pure value object; baking lives in the bakers.
 *
 * Grid layout: sample (ix, iy, iz) sits at world position
 *   origin + (ix, iy, iz) * cellSize
 * and is stored at flat index  ix + iy*nx + iz*nx*ny.
 */
final readonly class SdfVolume
{
    /**
     * @param float[] $data Flat signed-distance samples, length nx*ny*nz.
     */
    public function __construct(
        public int $nx,
        public int $ny,
        public int $nz,
        public Vec3 $origin,
        public float $cellSize,
        public array $data,
    ) {
        $expected = $nx * $ny * $nz;
        if ($nx < 2 || $ny < 2 || $nz < 2) {
            throw new \InvalidArgumentException('SdfVolume needs at least 2 samples per axis.');
        }
        if (count($data) !== $expected) {
            throw new \InvalidArgumentException(
                "SdfVolume data length " . count($data) . " != nx*ny*nz ({$expected})."
            );
        }
        if ($cellSize <= 0.0) {
            throw new \InvalidArgumentException('SdfVolume cellSize must be positive.');
        }
    }

    public function sampleCount(): int
    {
        return $this->nx * $this->ny * $this->nz;
    }

    /** World-space max corner of the sampled region. */
    public function max(): Vec3
    {
        return $this->origin->add(new Vec3(
            ($this->nx - 1) * $this->cellSize,
            ($this->ny - 1) * $this->cellSize,
            ($this->nz - 1) * $this->cellSize,
        ));
    }

    /** Raw stored distance at a grid cell (clamped to valid indices). */
    public function distanceAt(int $ix, int $iy, int $iz): float
    {
        $ix = max(0, min($this->nx - 1, $ix));
        $iy = max(0, min($this->ny - 1, $iy));
        $iz = max(0, min($this->nz - 1, $iz));
        return $this->data[$ix + $iy * $this->nx + $iz * $this->nx * $this->ny];
    }

    /**
     * Pack the field into RGBA8 voxel bytes for upload via vio_texture_3d()
     * (width*height*depth*4 bytes, Z-slices ascending — exactly the layout the
     * vio 3D-texture path expects). Signed distance is normalised to [0,1] over
     * [-range, +range] and replicated across RGB; A is left at 255.
     *
     * A single 8-bit channel is enough for the trace's near-surface band; the
     * shader recovers world distance as (sample*2 - 1) * range. Backends without
     * VIO_FEATURE_TEXTURE_3D ignore this and fall back to the analytic /
     * 2D-atlas path — this is the bridge for the Tier-A/volume path.
     */
    public function toRgba8(float $range = 4.0): string
    {
        $range = max($range, 1e-6);
        $bytes = '';
        foreach ($this->data as $d) {
            $n = ($d / $range) * 0.5 + 0.5;
            $b = max(0, min(255, (int)round($n * 255.0)));
            $bytes .= chr($b) . chr($b) . chr($b) . "\xFF";
        }
        return $bytes;
    }

    /**
     * Trilinearly interpolated distance at an arbitrary world point. Points
     * outside the grid clamp to the boundary cell — the same edge behaviour a
     * GPU sampler with clamp-to-edge gives, so CPU and GPU agree.
     */
    public function sample(Vec3 $p): float
    {
        $gx = ($p->x - $this->origin->x) / $this->cellSize;
        $gy = ($p->y - $this->origin->y) / $this->cellSize;
        $gz = ($p->z - $this->origin->z) / $this->cellSize;

        $gx = max(0.0, min((float)($this->nx - 1), $gx));
        $gy = max(0.0, min((float)($this->ny - 1), $gy));
        $gz = max(0.0, min((float)($this->nz - 1), $gz));

        $x0 = (int)floor($gx);
        $y0 = (int)floor($gy);
        $z0 = (int)floor($gz);
        $x1 = min($x0 + 1, $this->nx - 1);
        $y1 = min($y0 + 1, $this->ny - 1);
        $z1 = min($z0 + 1, $this->nz - 1);

        $fx = $gx - $x0;
        $fy = $gy - $y0;
        $fz = $gz - $z0;

        $c000 = $this->distanceAt($x0, $y0, $z0);
        $c100 = $this->distanceAt($x1, $y0, $z0);
        $c010 = $this->distanceAt($x0, $y1, $z0);
        $c110 = $this->distanceAt($x1, $y1, $z0);
        $c001 = $this->distanceAt($x0, $y0, $z1);
        $c101 = $this->distanceAt($x1, $y0, $z1);
        $c011 = $this->distanceAt($x0, $y1, $z1);
        $c111 = $this->distanceAt($x1, $y1, $z1);

        $c00 = $c000 + ($c100 - $c000) * $fx;
        $c10 = $c010 + ($c110 - $c010) * $fx;
        $c01 = $c001 + ($c101 - $c001) * $fx;
        $c11 = $c011 + ($c111 - $c011) * $fx;

        $c0 = $c00 + ($c10 - $c00) * $fy;
        $c1 = $c01 + ($c11 - $c01) * $fy;

        return $c0 + ($c1 - $c0) * $fz;
    }
}
