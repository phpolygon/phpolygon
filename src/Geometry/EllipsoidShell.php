<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A faceted ellipsoid shell: latitude rings of azimuth chords, one pane per (azimuth segment `k`, band `b`).
 * Band 0 is the south polar cap, band `bands - 1` the north cap; the upper half is `b >= bands / 2`. Azimuth 0
 * points along +X, increasing towards +Z. Panes wind outward; normals are the smooth gradient of the ellipsoid
 * so the facets shade as one surface.
 *
 * Built for domes, hulls and glass shells: panes can be selected ({@see build()} `$keep`), the upper half can be
 * pierced by a {@see PrismClip}, and {@see topAtRadius()} gives the smooth height for placement checks (the
 * faceted panes lie a little under it).
 */
final class EllipsoidShell
{
    /**
     * @param float $radius  equator radius (x and z)
     * @param float $height  vertical radius
     */
    public function __construct(
        public readonly float $centreX,
        public readonly float $centreY,
        public readonly float $centreZ,
        public readonly float $radius,
        public readonly float $height,
        public readonly int $segments,
        public readonly int $bands,
    ) {
        if ($segments < 3 || $bands < 2 || $bands % 2 !== 0 || $radius <= 0.0 || $height <= 0.0) {
            throw new \InvalidArgumentException('EllipsoidShell needs segments >= 3, an even bands >= 2 and positive radii.');
        }
    }

    /**
     * Ring vertex `$k` (azimuth, wraps) of latitude ring `$j` (0 = south pole ... `bands` = north pole).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function point(int $k, int $j): array
    {
        $phi = deg2rad(-90.0 + $j * (180.0 / $this->bands));
        $az = 2.0 * M_PI * ($k % $this->segments) / $this->segments;
        $r = $this->radius * cos($phi);

        return [$this->centreX + $r * cos($az), $this->centreY + $this->height * sin($phi), $this->centreZ + $r * sin($az)];
    }

    /**
     * Outward unit normal of the ellipsoid at a point on it (gradient of the implicit form).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function normal(float $x, float $y, float $z): array
    {
        $nx = ($x - $this->centreX) / ($this->radius * $this->radius);
        $ny = ($y - $this->centreY) / ($this->height * $this->height);
        $nz = ($z - $this->centreZ) / ($this->radius * $this->radius);
        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);

        return $len < 1e-12 ? [0.0, 1.0, 0.0] : [$nx / $len, $ny / $len, $nz / $len];
    }

    /** Height of the SMOOTH upper surface at distance `$r` from the axis, null at or beyond the equator. */
    public function topAtRadius(float $r): ?float
    {
        $q = $r / $this->radius;

        return $q >= 1.0 ? null : $this->centreY + $this->height * sqrt(1.0 - $q * $q);
    }

    /** Height of the smooth upper surface over a world point, null outside the equator circle. */
    public function topAt(float $x, float $z): ?float
    {
        return $this->topAtRadius(hypot($x - $this->centreX, $z - $this->centreZ));
    }

    /**
     * The upper-half pane [k, b] that lies over the world point (x, z), null outside the equator circle.
     *
     * @return array{0: int, 1: int}|null
     */
    public function upperPaneAt(float $x, float $z): ?array
    {
        $dist = hypot($x - $this->centreX, $z - $this->centreZ);
        if ($dist >= $this->radius) {
            return null;
        }
        $az = atan2($z - $this->centreZ, $x - $this->centreX);
        $az = $az < 0.0 ? $az + 2.0 * M_PI : $az;
        $phi = acos(min(1.0, $dist / $this->radius));

        return [
            (int) floor($az / (2.0 * M_PI / $this->segments)) % $this->segments,
            min($this->bands - 1, (int) floor(($phi + M_PI / 2.0) / (M_PI / $this->bands))),
        ];
    }

    /**
     * The triangles of pane (`$k`, `$b`) (a quad = two triangles, a polar cap = one), on the ellipsoid, outward winding.
     *
     * @return list<list<array{0: float, 1: float, 2: float}>>
     */
    public function panePolys(int $k, int $b): array
    {
        $a = $this->point($k, $b);
        $c = $this->point($k + 1, $b);
        $d = $this->point($k + 1, $b + 1);
        $e = $this->point($k, $b + 1);
        if ($b === 0) {
            return [[$a, $d, $e]];
        }
        if ($b === $this->bands - 1) {
            return [[$a, $c, $e]];
        }

        return [[$a, $c, $d], [$a, $d, $e]];
    }

    /**
     * The shell as one mesh.
     *
     * @param \Closure(int, int): bool|null $keep  pane filter (k, b), null keeps every pane
     * @param bool $lowerHalf                      include the lower half (bands below the equator)
     * @param PrismClip|null $pierce               cut out of the upper half only; the lower half stays whole
     */
    public function build(?\Closure $keep = null, bool $lowerHalf = true, ?PrismClip $pierce = null): MeshData
    {
        $m = new MeshAccumulator();
        $half = intdiv($this->bands, 2);
        $normalAt = $this->normal(...);
        for ($b = $lowerHalf ? 0 : $half; $b < $this->bands; $b++) {
            for ($k = 0; $k < $this->segments; $k++) {
                if ($keep !== null && !$keep($k, $b)) {
                    continue;
                }
                foreach ($this->panePolys($k, $b) as $poly) {
                    $pieces = $pierce !== null && $b >= $half ? $pierce->outside($poly) : [$poly];
                    foreach ($pieces as $piece) {
                        $m->fanSmooth($piece, $normalAt);
                    }
                }
            }
        }

        return $m->build();
    }
}
