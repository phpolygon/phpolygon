<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A horizontal 2D frame in the XZ plane: an origin and a unit direction `u`; `w` is `u` turned a quarter
 * ({@see point()} maps frame coordinates (u, y, w) to world points, {@see local()} maps back). Rooms, shafts and
 * pads that stand at an angle to the world axes are described in their own frame and built axis-aligned in it.
 * Immutable; the direction is normalised on construction.
 */
final class PlanarFrame
{
    public readonly float $dirX;
    public readonly float $dirZ;

    public function __construct(
        public readonly float $originX,
        public readonly float $originZ,
        float $dirX,
        float $dirZ,
    ) {
        $len = hypot($dirX, $dirZ);
        if ($len < 1e-12) {
            throw new \InvalidArgumentException('PlanarFrame needs a non-zero direction.');
        }
        $this->dirX = $dirX / $len;
        $this->dirZ = $dirZ / $len;
    }

    /**
     * Frame point (u, y, w) to world point [x, y, z].
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function point(float $u, float $y, float $w): array
    {
        return [
            $this->originX + $u * $this->dirX - $w * $this->dirZ,
            $y,
            $this->originZ + $u * $this->dirZ + $w * $this->dirX,
        ];
    }

    /**
     * World (x, z) to frame [u, w].
     *
     * @return array{0: float, 1: float}
     */
    public function local(float $x, float $z): array
    {
        $dx = $x - $this->originX;
        $dz = $z - $this->originZ;

        return [$dx * $this->dirX + $dz * $this->dirZ, -$dx * $this->dirZ + $dz * $this->dirX];
    }

    /**
     * World direction of the frame's u axis, [x, 0, z].
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function uAxis(): array
    {
        return [$this->dirX, 0.0, $this->dirZ];
    }

    /**
     * World direction of the frame's w axis, [x, 0, z].
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function wAxis(): array
    {
        return [-$this->dirZ, 0.0, $this->dirX];
    }
}

