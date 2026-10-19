<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Layout;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * A straight run along a surface where parts may attach anywhere: a picture
 * rail, a cable tray, the strip on a wall where screens, shelves or wall lights
 * go.
 *
 * `start`/`end` lie ON the carrying surface; `normal` is the unit direction away
 * from it. Attach with {@see pointAt()} (0 = start, 1 = end) or {@see pointAtDistance()}.
 *
 * `kind` is a free tag chosen by the game (e.g. "screen", "trim", "light").
 */
final readonly class MountLine
{
    public Vec3 $normal;

    public function __construct(
        public string $name,
        public Vec3 $start,
        public Vec3 $end,
        Vec3 $normal,
        public string $kind = '',
    ) {
        $this->normal = Socket::unit($normal, 'MountLine normal');
    }

    public function length(): float
    {
        return $this->end->sub($this->start)->length();
    }

    /** Unit direction from start to end (zero vector for a degenerate line). */
    public function direction(): Vec3
    {
        $d = $this->end->sub($this->start);

        return $d->lengthSquared() > 0.0 ? $d->normalize() : Vec3::zero();
    }

    /** Point at fraction $t of the run (clamped to 0..1). */
    public function pointAt(float $t): Vec3
    {
        return $this->start->lerp($this->end, max(0.0, min(1.0, $t)));
    }

    /** Point $metres from the start along the run (clamped to the line). */
    public function pointAtDistance(float $metres): Vec3
    {
        $length = $this->length();

        return $length > 0.0 ? $this->pointAt($metres / $length) : $this->start;
    }

    public function midpoint(): Vec3
    {
        return $this->pointAt(0.5);
    }

    /** The line in another space (rigid transforms; scale is dropped from the normal). */
    public function transformed(Mat4 $matrix): self
    {
        return new self(
            $this->name,
            $matrix->transformPoint($this->start),
            $matrix->transformPoint($this->end),
            $matrix->transformDirection($this->normal),
            $this->kind,
        );
    }
}
