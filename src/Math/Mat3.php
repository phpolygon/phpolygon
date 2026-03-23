<?php

declare(strict_types=1);

namespace PHPolygon\Math;

/**
 * 3x3 matrix for 2D transformations.
 * Stored in column-major order: [m00, m10, m20, m01, m11, m21, m02, m12, m22]
 *
 * Layout:
 *   m00 m01 m02
 *   m10 m11 m12
 *   m20 m21 m22
 */
class Mat3
{
    /** @var float[] */
    private array $m;

    /** @param float[] $values Column-major 9 floats */
    public function __construct(array $values)
    {
        $this->m = $values;
    }

    public static function identity(): self
    {
        return new self([
            1.0, 0.0, 0.0,
            0.0, 1.0, 0.0,
            0.0, 0.0, 1.0,
        ]);
    }

    public static function translation(float $tx, float $ty): self
    {
        return new self([
            1.0, 0.0, 0.0,
            0.0, 1.0, 0.0,
            $tx,  $ty,  1.0,
        ]);
    }

    public static function rotation(float $radians): self
    {
        $c = cos($radians);
        $s = sin($radians);
        return new self([
            $c,   $s,  0.0,
            -$s,  $c,  0.0,
            0.0, 0.0,  1.0,
        ]);
    }

    public static function scaling(float $sx, float $sy): self
    {
        return new self([
            $sx,  0.0, 0.0,
            0.0,  $sy,  0.0,
            0.0,  0.0,  1.0,
        ]);
    }

    /**
     * Build a TRS matrix: translate, then rotate, then scale.
     */
    public static function trs(Vec2 $position, float $rotation, Vec2 $scale): self
    {
        $c = cos($rotation);
        $s = sin($rotation);
        return new self([
            $scale->x * $c,   $scale->x * $s,  0.0,
            -$scale->y * $s,  $scale->y * $c,  0.0,
            $position->x,     $position->y,     1.0,
        ]);
    }

    public function multiply(Mat3 $other): self
    {
        $a = $this->m;
        $b = $other->m;
        return new self([
            $a[0]*$b[0] + $a[3]*$b[1] + $a[6]*$b[2],
            $a[1]*$b[0] + $a[4]*$b[1] + $a[7]*$b[2],
            $a[2]*$b[0] + $a[5]*$b[1] + $a[8]*$b[2],

            $a[0]*$b[3] + $a[3]*$b[4] + $a[6]*$b[5],
            $a[1]*$b[3] + $a[4]*$b[4] + $a[7]*$b[5],
            $a[2]*$b[3] + $a[5]*$b[4] + $a[8]*$b[5],

            $a[0]*$b[6] + $a[3]*$b[7] + $a[6]*$b[8],
            $a[1]*$b[6] + $a[4]*$b[7] + $a[7]*$b[8],
            $a[2]*$b[6] + $a[5]*$b[7] + $a[8]*$b[8],
        ]);
    }

    public function transformPoint(Vec2 $point): Vec2
    {
        return new Vec2(
            $this->m[0] * $point->x + $this->m[3] * $point->y + $this->m[6],
            $this->m[1] * $point->x + $this->m[4] * $point->y + $this->m[7],
        );
    }

    public function transformDirection(Vec2 $dir): Vec2
    {
        return new Vec2(
            $this->m[0] * $dir->x + $this->m[3] * $dir->y,
            $this->m[1] * $dir->x + $this->m[4] * $dir->y,
        );
    }

    public function inverse(): self
    {
        $m = $this->m;
        $det = $m[0]*($m[4]*$m[8] - $m[7]*$m[5])
             - $m[3]*($m[1]*$m[8] - $m[7]*$m[2])
             + $m[6]*($m[1]*$m[5] - $m[4]*$m[2]);

        if (abs($det) < 1e-10) {
            return self::identity();
        }

        $invDet = 1.0 / $det;
        return new self([
            ($m[4]*$m[8] - $m[5]*$m[7]) * $invDet,
            ($m[2]*$m[7] - $m[1]*$m[8]) * $invDet,
            ($m[1]*$m[5] - $m[2]*$m[4]) * $invDet,

            ($m[5]*$m[6] - $m[3]*$m[8]) * $invDet,
            ($m[0]*$m[8] - $m[2]*$m[6]) * $invDet,
            ($m[2]*$m[3] - $m[0]*$m[5]) * $invDet,

            ($m[3]*$m[7] - $m[4]*$m[6]) * $invDet,
            ($m[1]*$m[6] - $m[0]*$m[7]) * $invDet,
            ($m[0]*$m[4] - $m[1]*$m[3]) * $invDet,
        ]);
    }

    public function get(int $row, int $col): float
    {
        return $this->m[$col * 3 + $row];
    }

    /** @return float[] */
    public function toArray(): array
    {
        return $this->m;
    }
}
