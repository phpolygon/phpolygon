<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Layout;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;

/**
 * A single point where another part may attach: a light fixture on a ceiling, a
 * vent, a hanging sign, a hook on a wall.
 *
 * `position` lies ON the carrying surface; `normal` is the unit direction away
 * from that surface (down for a ceiling, into the room for a wall). Whatever is
 * mounted builds outwards along the normal from the point.
 *
 * `kind` is a free tag chosen by the game (e.g. "light", "vent", "sign").
 */
final readonly class MountPoint
{
    public Vec3 $normal;

    public function __construct(
        public string $name,
        public Vec3 $position,
        Vec3 $normal,
        public string $kind = '',
    ) {
        $this->normal = Socket::unit($normal, 'MountPoint normal');
    }

    /** The point $distance along the normal (e.g. the face of a 5 cm plate). */
    public function offset(float $distance): Vec3
    {
        return $this->position->add($this->normal->mul($distance));
    }

    /** The mount point in another space (rigid transforms; scale is dropped from the normal). */
    public function transformed(Mat4 $matrix): self
    {
        return new self(
            $this->name,
            $matrix->transformPoint($this->position),
            $matrix->transformDirection($this->normal),
            $this->kind,
        );
    }
}
