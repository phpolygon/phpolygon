<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Layout;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * An axis-aligned box of space that a level part publishes for the others to
 * respect: a walk zone, a keep-out zone in front of a door, the viewing zone in
 * front of a screen, a station's footprint.
 *
 * Coordinates are in the publisher's space (usually room-local); move the zone
 * into another space with {@see transformed()}. A zone that only matters on the
 * floor plan uses the full height of its room, or test it with {@see containsXZ()}.
 *
 * `kind` is a free tag chosen by the game (e.g. "walk", "keep_out", "viewing");
 * the engine attaches no meaning to it.
 */
final readonly class Zone
{
    public Vec3 $min;

    public Vec3 $max;

    public function __construct(
        public string $name,
        Vec3 $min,
        Vec3 $max,
        public string $kind = '',
    ) {
        // Accept corners in any order: the box is always min <= max per axis.
        $this->min = new Vec3(min($min->x, $max->x), min($min->y, $max->y), min($min->z, $max->z));
        $this->max = new Vec3(max($min->x, $max->x), max($min->y, $max->y), max($min->z, $max->z));
    }

    /** A zone around a centre with the given half extents. */
    public static function around(string $name, Vec3 $center, Vec3 $halfExtents, string $kind = ''): self
    {
        return new self($name, $center->sub($halfExtents), $center->add($halfExtents), $kind);
    }

    public function center(): Vec3
    {
        return $this->min->add($this->max)->mul(0.5);
    }

    public function size(): Vec3
    {
        return $this->max->sub($this->min);
    }

    public function contains(Vec3 $p, float $epsilon = 1e-6): bool
    {
        return $p->x >= $this->min->x - $epsilon && $p->x <= $this->max->x + $epsilon
            && $p->y >= $this->min->y - $epsilon && $p->y <= $this->max->y + $epsilon
            && $p->z >= $this->min->z - $epsilon && $p->z <= $this->max->z + $epsilon;
    }

    /** Floor-plan test: ignores the height. */
    public function containsXZ(Vec3 $p, float $epsilon = 1e-6): bool
    {
        return $p->x >= $this->min->x - $epsilon && $p->x <= $this->max->x + $epsilon
            && $p->z >= $this->min->z - $epsilon && $p->z <= $this->max->z + $epsilon;
    }

    /**
     * Whether the two boxes share volume. Touching faces do not count – two
     * zones that only meet at a wall are neighbours, not a conflict.
     */
    public function overlaps(self $other): bool
    {
        return $this->min->x < $other->max->x && $this->max->x > $other->min->x
            && $this->min->y < $other->max->y && $this->max->y > $other->min->y
            && $this->min->z < $other->max->z && $this->max->z > $other->min->z;
    }

    /** Floor-plan overlap: ignores the height. */
    public function overlapsXZ(self $other): bool
    {
        return $this->min->x < $other->max->x && $this->max->x > $other->min->x
            && $this->min->z < $other->max->z && $this->max->z > $other->min->z;
    }

    /** The zone grown (or, negative, shrunk) by $margin on every side. */
    public function expanded(float $margin): self
    {
        $m = new Vec3($margin, $margin, $margin);

        return new self($this->name, $this->min->sub($m), $this->max->add($m), $this->kind);
    }

    /**
     * The zone in another space: the bounding box of its eight transformed
     * corners. Exact for translations and quarter turns about an axis (the
     * usual way rooms are placed); a free rotation yields the enclosing box.
     */
    public function transformed(Mat4 $matrix): self
    {
        $min = new Vec3(INF, INF, INF);
        $max = new Vec3(-INF, -INF, -INF);
        foreach ([$this->min->x, $this->max->x] as $x) {
            foreach ([$this->min->y, $this->max->y] as $y) {
                foreach ([$this->min->z, $this->max->z] as $z) {
                    $p = $matrix->transformPoint(new Vec3($x, $y, $z));
                    $min = new Vec3(min($min->x, $p->x), min($min->y, $p->y), min($min->z, $p->z));
                    $max = new Vec3(max($max->x, $p->x), max($max->y, $p->y), max($max->z, $p->z));
                }
            }
        }

        return new self($this->name, $min, $max, $this->kind);
    }
}
