<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Layout;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * A hole through a wall that other parts must keep clear or fill: a doorway, a
 * window, a pass-through, a hatch.
 *
 * `center` is the middle of the opening's BOTTOM edge (the sill, or the floor
 * for a door) in the wall's centre plane; `normal` is the unit direction you
 * pass through it (the side it is entered from is behind the normal). `up` is
 * the opening's vertical (+Y unless the wall is tilted). Width runs along
 * `up × normal`, height along `up`.
 *
 * `kind` is a free tag chosen by the game (e.g. "door", "window", "passthrough").
 */
final readonly class Opening
{
    public Vec3 $normal;

    public Vec3 $up;

    public function __construct(
        public string $name,
        public Vec3 $center,
        Vec3 $normal,
        public float $width,
        public float $height,
        public string $kind = '',
        ?Vec3 $up = null,
    ) {
        if ($width <= 0.0 || $height <= 0.0) {
            throw new \InvalidArgumentException("Opening '{$name}' needs a positive width and height");
        }
        $this->normal = Socket::unit($normal, 'Opening normal');
        $this->up = Socket::unit($up ?? new Vec3(0.0, 1.0, 0.0), 'Opening up');
    }

    /** Unit direction of the width: `up × normal` (+X for an opening passed along +Z). */
    public function across(): Vec3
    {
        return $this->up->cross($this->normal)->normalize();
    }

    /** Middle of the opening (half way up). */
    public function middle(): Vec3
    {
        return $this->center->add($this->up->mul($this->height * 0.5));
    }

    /** Middle of the top edge (the lintel) – where a sign above a door goes. */
    public function top(): Vec3
    {
        return $this->center->add($this->up->mul($this->height));
    }

    /**
     * The space the opening needs kept free on both sides: its own rectangle
     * extruded $depth metres along and against the normal. Axis-aligned, so
     * exact for openings in axis-aligned walls.
     */
    public function clearance(float $depth, string $kind = 'keep_out'): Zone
    {
        $half = $this->across()->mul($this->width * 0.5);
        $through = $this->normal->mul($depth);
        $a = $this->center->sub($half)->sub($through);
        $b = $this->center->add($half)->add($through)->add($this->up->mul($this->height));

        return new Zone($this->name, $a, $b, $kind);
    }

    /** The opening in another space (rigid transforms; scale is dropped from the axes). */
    public function transformed(Mat4 $matrix): self
    {
        return new self(
            $this->name,
            $matrix->transformPoint($this->center),
            $matrix->transformDirection($this->normal),
            $this->width,
            $this->height,
            $this->kind,
            $matrix->transformDirection($this->up),
        );
    }
}
