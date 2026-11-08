<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing\Volume;

use PHPolygon\Math\Vec3;

/**
 * Baked light on a uniform world grid: how much of the sky and how much of the sun a
 * point sees, traced once through a signed-distance field
 * ({@see \PHPolygon\Fieldtracing\Bake\BakedLightBaker}). The renderer looks it up per
 * pixel instead of tracing the field every frame
 * ({@see \PHPolygon\Rendering\Command\SetBakedLight}).
 *
 * Grid layout as {@see SdfVolume}: sample (ix, iy, iz) sits at
 *   origin + (ix, iy, iz) * cellSize
 * and is stored at flat index ix + iy*nx + iz*nx*ny. Values are 0..1 (1 = open sky,
 * full sun).
 */
final readonly class BakedLightVolume
{
    /**
     * @param list<float> $sky Sky visibility per sample.
     * @param list<float> $sun Visibility of the sun in {@see $towardSun} per sample.
     */
    public function __construct(
        public int $nx,
        public int $ny,
        public int $nz,
        public Vec3 $origin,
        public float $cellSize,
        public array $sky,
        public array $sun,
        public Vec3 $towardSun,
    ) {
        $expected = $nx * $ny * $nz;
        if ($nx < 2 || $ny < 2 || $nz < 2) {
            throw new \InvalidArgumentException('BakedLightVolume needs at least 2 samples per axis.');
        }
        if (count($sky) !== $expected || count($sun) !== $expected) {
            throw new \InvalidArgumentException("BakedLightVolume needs {$expected} sky and sun samples.");
        }
        if ($cellSize <= 0.0) {
            throw new \InvalidArgumentException('BakedLightVolume cellSize must be positive.');
        }
    }

    /** World extent covered by the samples (the 3D texture spans exactly this box). */
    public function size(): Vec3
    {
        return new Vec3(($this->nx - 1) * $this->cellSize, ($this->ny - 1) * $this->cellSize, ($this->nz - 1) * $this->cellSize);
    }

    public function skyAt(int $ix, int $iy, int $iz): float
    {
        return $this->sky[$this->index($ix, $iy, $iz)];
    }

    public function sunAt(int $ix, int $iy, int $iz): float
    {
        return $this->sun[$this->index($ix, $iy, $iz)];
    }

    /**
     * RGBA8 voxels for vio_texture_3d (Z-slices ascending): R = sky, G = sun, B = 0,
     * A = 255.
     */
    public function toRgba8(): string
    {
        $bytes = '';
        foreach ($this->sky as $i => $s) {
            $bytes .= chr(max(0, min(255, (int) round($s * 255.0))))
                . chr(max(0, min(255, (int) round($this->sun[$i] * 255.0))))
                . "\x00\xFF";
        }
        return $bytes;
    }

    private function index(int $ix, int $iy, int $iz): int
    {
        $ix = max(0, min($this->nx - 1, $ix));
        $iy = max(0, min($this->ny - 1, $iy));
        $iz = max(0, min($this->nz - 1, $iz));
        return $ix + $iy * $this->nx + $iz * $this->nx * $this->ny;
    }
}
