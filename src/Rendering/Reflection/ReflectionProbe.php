<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Reflection;

use PHPolygon\Math\Vec3;

/**
 * A local reflection probe: a cubemap captured at $position, valid inside the
 * world-space box $min..$max. Inside the box, reflections are box-projected (a ray
 * from the surface point is intersected with the box and the cubemap is looked up
 * toward the hit, seen from the capture point), so a reflected building sits where
 * it stands instead of drifting with the viewer. Toward the box faces the probe
 * fades out over $blend metres to whatever lies below it (the next probe or the
 * snippet's own fallback).
 */
final readonly class ReflectionProbe
{
    public function __construct(
        /** CubemapRegistry id of the captured cubemap. */
        public string $cubemapId,
        public Vec3 $position,
        public Vec3 $min,
        public Vec3 $max,
        public float $blend = 8.0,
    ) {
        if ($max->x <= $min->x || $max->y <= $min->y || $max->z <= $min->z) {
            throw new \InvalidArgumentException("Reflection probe '{$cubemapId}': the box is empty");
        }
        if ($blend <= 0.0) {
            throw new \InvalidArgumentException("Reflection probe '{$cubemapId}': blend must be positive");
        }
    }

    /** A probe whose box is centred on $position with half extents $half. */
    public static function around(string $cubemapId, Vec3 $position, Vec3 $half, float $blend = 8.0): self
    {
        return new self($cubemapId, $position, $position->sub($half), $position->add($half), $blend);
    }

    /** Distance from $point to the box, 0 inside. */
    public function distanceTo(Vec3 $point): float
    {
        $dx = max($this->min->x - $point->x, 0.0, $point->x - $this->max->x);
        $dy = max($this->min->y - $point->y, 0.0, $point->y - $this->max->y);
        $dz = max($this->min->z - $point->z, 0.0, $point->z - $this->max->z);

        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }

    /** Box volume, the tie-breaker between probes that both hold a point (smaller wins). */
    public function volume(): float
    {
        return ($this->max->x - $this->min->x) * ($this->max->y - $this->min->y) * ($this->max->z - $this->min->z);
    }
}
