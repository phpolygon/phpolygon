<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Layout;

use PHPolygon\Math\Vec3;

/**
 * Shared helpers of the layout socket types ({@see Opening}, {@see MountPoint},
 * {@see MountLine}, {@see Zone}). Not a socket itself.
 *
 * @internal
 */
final class Socket
{
    private function __construct() {}

    /** Normalise a direction, rejecting the zero vector (a socket without a facing is a bug). */
    public static function unit(Vec3 $v, string $what): Vec3
    {
        if ($v->lengthSquared() < 1e-12) {
            throw new \InvalidArgumentException("{$what} must not be the zero vector");
        }

        return $v->normalize();
    }
}
