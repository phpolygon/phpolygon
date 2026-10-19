<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * One cross-section of an {@see EllipseLoftMesh}: a closed, smooth ring in the
 * horizontal plane at height $y, centred on ($cx, $cz), reaching $xPos toward
 * +X, $xNeg toward −X, $zPos toward +Z and $zNeg toward −Z (all ≥ 0).
 *
 * Equal radii give an ellipse; unequal ones give an egg-like section that is
 * fuller on one side – a calf that swells behind, a chest deeper in front – with
 * no crease where the halves meet.
 */
final class LoftRing
{
    public function __construct(
        public readonly float $y,
        public readonly float $xPos,
        public readonly float $xNeg,
        public readonly float $zPos,
        public readonly float $zNeg,
        public readonly float $cx = 0.0,
        public readonly float $cz = 0.0,
    ) {
    }

    /** A plain ellipse (symmetric section) with half-axes $rx and $rz. */
    public static function ellipse(float $y, float $rx, float $rz, float $cx = 0.0, float $cz = 0.0): self
    {
        return new self($y, $rx, $rx, $rz, $rz, $cx, $cz);
    }

    /** The same ring scaled about its centre (radii × $factor), moved to height $y. */
    public function scaled(float $factor, float $y): self
    {
        return new self(
            $y,
            $this->xPos * $factor,
            $this->xNeg * $factor,
            $this->zPos * $factor,
            $this->zNeg * $factor,
            $this->cx,
            $this->cz,
        );
    }

    /**
     * Point on the ring at azimuth $phi (0 = +Z, π/2 = +X). The half-radii are
     * blended through the section (x = cx + s·(m + d·s) with s = sin φ), so the
     * outline and its tangent stay continuous where +X meets −X. The outline
     * stays within the four radii as long as the larger of two opposite radii
     * is at most three times the smaller; beyond that it bulges slightly past
     * the smaller one.
     *
     * @return array{0: float, 1: float} [x, z]
     */
    public function pointAt(float $phi): array
    {
        $s = sin($phi);
        $c = cos($phi);
        $mx = ($this->xPos + $this->xNeg) / 2.0;
        $dx = ($this->xPos - $this->xNeg) / 2.0;
        $mz = ($this->zPos + $this->zNeg) / 2.0;
        $dz = ($this->zPos - $this->zNeg) / 2.0;

        return [$this->cx + $s * ($mx + $dx * $s), $this->cz + $c * ($mz + $dz * $c)];
    }
}
