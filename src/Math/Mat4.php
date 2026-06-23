<?php

declare(strict_types=1);

namespace PHPolygon\Math;

/**
 * 4x4 matrix for 3D transformations.
 * Stored in column-major order: [m00, m10, m20, m30, m01, m11, m21, m31, m02, m12, m22, m32, m03, m13, m23, m33]
 *
 * Layout (row x col):
 *   m00 m01 m02 m03
 *   m10 m11 m12 m13
 *   m20 m21 m22 m23
 *   m30 m31 m32 m33
 */
class Mat4
{
    /** @var float[] */
    private array $m;

    /** @param float[] $values Column-major 16 floats */
    public function __construct(array $values)
    {
        $this->m = $values;
    }

    public static function identity(): self
    {
        return new self([
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    public static function translation(float $tx, float $ty, float $tz): self
    {
        return new self([
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            $tx, $ty, $tz, 1.0,
        ]);
    }

    public static function scaling(float $sx, float $sy, float $sz): self
    {
        return new self([
            $sx,  0.0, 0.0, 0.0,
            0.0,  $sy, 0.0, 0.0,
            0.0,  0.0, $sz, 0.0,
            0.0,  0.0, 0.0, 1.0,
        ]);
    }

    public static function rotationX(float $radians): self
    {
        $c = cos($radians);
        $s = sin($radians);
        return new self([
            1.0, 0.0, 0.0, 0.0,
            0.0,  $c,  $s, 0.0,
            0.0, -$s,  $c, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    public static function rotationY(float $radians): self
    {
        $c = cos($radians);
        $s = sin($radians);
        return new self([
             $c, 0.0, -$s, 0.0,
            0.0, 1.0, 0.0, 0.0,
             $s, 0.0,  $c, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    public static function rotationZ(float $radians): self
    {
        $c = cos($radians);
        $s = sin($radians);
        return new self([
             $c,  $s, 0.0, 0.0,
            -$s,  $c, 0.0, 0.0,
            0.0, 0.0, 1.0, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]);
    }

    /**
     * Build a TRS (translate * rotate * scale) matrix.
     * Implemented after Quaternion is available.
     */
    public static function trs(Vec3 $position, Quaternion $rotation, Vec3 $scale): self
    {
        $r = $rotation->toRotationMatrix();
        $rm = $r->toArray();

        // Apply scale to the rotation columns, then set translation
        return new self([
            $rm[0] * $scale->x, $rm[1] * $scale->x, $rm[2] * $scale->x, 0.0,
            $rm[4] * $scale->y, $rm[5] * $scale->y, $rm[6] * $scale->y, 0.0,
            $rm[8] * $scale->z, $rm[9] * $scale->z, $rm[10] * $scale->z, 0.0,
            $position->x, $position->y, $position->z, 1.0,
        ]);
    }

    /**
     * Standard OpenGL perspective projection matrix (NDC depth range [-1, 1]).
     * For Vulkan, compose with a clip-space correction matrix in the backend.
     */
    public static function perspective(float $fovY, float $aspect, float $near, float $far): self
    {
        $tanHalfFov = tan($fovY / 2.0);
        $f = 1.0 / $tanHalfFov;
        $nf = 1.0 / ($near - $far);

        return new self([
            $f / $aspect, 0.0,  0.0,                        0.0,
            0.0,          $f,   0.0,                        0.0,
            0.0,          0.0,  ($far + $near) * $nf,      -1.0,
            0.0,          0.0,  (2.0 * $far * $near) * $nf, 0.0,
        ]);
    }

    public static function orthographic(float $left, float $right, float $bottom, float $top, float $near, float $far): self
    {
        $rl = 1.0 / ($right - $left);
        $tb = 1.0 / ($top - $bottom);
        $fn = 1.0 / ($far - $near);

        return new self([
            2.0 * $rl,               0.0,                     0.0,                  0.0,
            0.0,                     2.0 * $tb,               0.0,                  0.0,
            0.0,                     0.0,                    -2.0 * $fn,             0.0,
            -($right + $left) * $rl, -($top + $bottom) * $tb, -($far + $near) * $fn, 1.0,
        ]);
    }

    public static function lookAt(Vec3 $eye, Vec3 $center, Vec3 $up): self
    {
        $f = $center->sub($eye)->normalize(); // forward
        $r = $f->cross($up)->normalize();     // right
        $u = $r->cross($f);                   // up (recalculated)

        return new self([
             $r->x,          $u->x,         -$f->x,         0.0,
             $r->y,          $u->y,         -$f->y,         0.0,
             $r->z,          $u->z,         -$f->z,         0.0,
            -$r->dot($eye), -$u->dot($eye),  $f->dot($eye), 1.0,
        ]);
    }

    public function multiply(Mat4 $other): self
    {
        $a = $this->m;
        $b = $other->m;

        return new self([
            // Column 0
            $a[0]*$b[0]  + $a[4]*$b[1]  + $a[8]*$b[2]  + $a[12]*$b[3],
            $a[1]*$b[0]  + $a[5]*$b[1]  + $a[9]*$b[2]  + $a[13]*$b[3],
            $a[2]*$b[0]  + $a[6]*$b[1]  + $a[10]*$b[2] + $a[14]*$b[3],
            $a[3]*$b[0]  + $a[7]*$b[1]  + $a[11]*$b[2] + $a[15]*$b[3],
            // Column 1
            $a[0]*$b[4]  + $a[4]*$b[5]  + $a[8]*$b[6]  + $a[12]*$b[7],
            $a[1]*$b[4]  + $a[5]*$b[5]  + $a[9]*$b[6]  + $a[13]*$b[7],
            $a[2]*$b[4]  + $a[6]*$b[5]  + $a[10]*$b[6] + $a[14]*$b[7],
            $a[3]*$b[4]  + $a[7]*$b[5]  + $a[11]*$b[6] + $a[15]*$b[7],
            // Column 2
            $a[0]*$b[8]  + $a[4]*$b[9]  + $a[8]*$b[10] + $a[12]*$b[11],
            $a[1]*$b[8]  + $a[5]*$b[9]  + $a[9]*$b[10] + $a[13]*$b[11],
            $a[2]*$b[8]  + $a[6]*$b[9]  + $a[10]*$b[10]+ $a[14]*$b[11],
            $a[3]*$b[8]  + $a[7]*$b[9]  + $a[11]*$b[10]+ $a[15]*$b[11],
            // Column 3
            $a[0]*$b[12] + $a[4]*$b[13] + $a[8]*$b[14] + $a[12]*$b[15],
            $a[1]*$b[12] + $a[5]*$b[13] + $a[9]*$b[14] + $a[13]*$b[15],
            $a[2]*$b[12] + $a[6]*$b[13] + $a[10]*$b[14]+ $a[14]*$b[15],
            $a[3]*$b[12] + $a[7]*$b[13] + $a[11]*$b[14]+ $a[15]*$b[15],
        ]);
    }

    public function multiplyVec4(Vec4 $v): Vec4
    {
        $m = $this->m;
        return new Vec4(
            $m[0]*$v->x + $m[4]*$v->y + $m[8]*$v->z  + $m[12]*$v->w,
            $m[1]*$v->x + $m[5]*$v->y + $m[9]*$v->z  + $m[13]*$v->w,
            $m[2]*$v->x + $m[6]*$v->y + $m[10]*$v->z + $m[14]*$v->w,
            $m[3]*$v->x + $m[7]*$v->y + $m[11]*$v->z + $m[15]*$v->w,
        );
    }

    public function transformPoint(Vec3 $point): Vec3
    {
        $v = $this->multiplyVec4(new Vec4($point->x, $point->y, $point->z, 1.0));
        if (abs($v->w) > 1e-10 && abs($v->w - 1.0) > 1e-10) {
            return new Vec3($v->x / $v->w, $v->y / $v->w, $v->z / $v->w);
        }
        return new Vec3($v->x, $v->y, $v->z);
    }

    public function transformDirection(Vec3 $dir): Vec3
    {
        $v = $this->multiplyVec4(new Vec4($dir->x, $dir->y, $dir->z, 0.0));
        return new Vec3($v->x, $v->y, $v->z);
    }

    public function transpose(): self
    {
        $m = $this->m;
        return new self([
            $m[0], $m[4], $m[8],  $m[12],
            $m[1], $m[5], $m[9],  $m[13],
            $m[2], $m[6], $m[10], $m[14],
            $m[3], $m[7], $m[11], $m[15],
        ]);
    }

    public function inverse(): self
    {
        $m = $this->m;

        $b00 = $m[0]  * $m[5]  - $m[1]  * $m[4];
        $b01 = $m[0]  * $m[6]  - $m[2]  * $m[4];
        $b02 = $m[0]  * $m[7]  - $m[3]  * $m[4];
        $b03 = $m[1]  * $m[6]  - $m[2]  * $m[5];
        $b04 = $m[1]  * $m[7]  - $m[3]  * $m[5];
        $b05 = $m[2]  * $m[7]  - $m[3]  * $m[6];
        $b06 = $m[8]  * $m[13] - $m[9]  * $m[12];
        $b07 = $m[8]  * $m[14] - $m[10] * $m[12];
        $b08 = $m[8]  * $m[15] - $m[11] * $m[12];
        $b09 = $m[9]  * $m[14] - $m[10] * $m[13];
        $b10 = $m[9]  * $m[15] - $m[11] * $m[13];
        $b11 = $m[10] * $m[15] - $m[11] * $m[14];

        $det = $b00*$b11 - $b01*$b10 + $b02*$b09 + $b03*$b08 - $b04*$b07 + $b05*$b06;

        if (abs($det) < 1e-10) {
            return self::identity();
        }

        $invDet = 1.0 / $det;

        return new self([
            ( $m[5]*$b11  - $m[6]*$b10  + $m[7]*$b09)  * $invDet,
            (-$m[1]*$b11  + $m[2]*$b10  - $m[3]*$b09)  * $invDet,
            ( $m[13]*$b05 - $m[14]*$b04 + $m[15]*$b03) * $invDet,
            (-$m[9]*$b05  + $m[10]*$b04 - $m[11]*$b03) * $invDet,

            (-$m[4]*$b11  + $m[6]*$b08  - $m[7]*$b07)  * $invDet,
            ( $m[0]*$b11  - $m[2]*$b08  + $m[3]*$b07)  * $invDet,
            (-$m[12]*$b05 + $m[14]*$b02 - $m[15]*$b01) * $invDet,
            ( $m[8]*$b05  - $m[10]*$b02 + $m[11]*$b01) * $invDet,

            ( $m[4]*$b10  - $m[5]*$b08  + $m[7]*$b06)  * $invDet,
            (-$m[0]*$b10  + $m[1]*$b08  - $m[3]*$b06)  * $invDet,
            ( $m[12]*$b04 - $m[13]*$b02 + $m[15]*$b00) * $invDet,
            (-$m[8]*$b04  + $m[9]*$b02  - $m[11]*$b00) * $invDet,

            (-$m[4]*$b09  + $m[5]*$b07  - $m[6]*$b06)  * $invDet,
            ( $m[0]*$b09  - $m[1]*$b07  + $m[2]*$b06)  * $invDet,
            (-$m[12]*$b03 + $m[13]*$b01 - $m[14]*$b00) * $invDet,
            ( $m[8]*$b03  - $m[9]*$b01  + $m[10]*$b00) * $invDet,
        ]);
    }

    public function get(int $row, int $col): float
    {
        return $this->m[$col * 4 + $row];
    }

    public function getTranslation(): Vec3
    {
        return new Vec3($this->m[12], $this->m[13], $this->m[14]);
    }

    /**
     * Single translation components, without allocating a {@see Vec3}. For
     * allocation-sensitive hot loops (per-entity culling/binning) that only
     * need one or two axes of the world position.
     */
    public function translationX(): float
    {
        return $this->m[12];
    }

    public function translationY(): float
    {
        return $this->m[13];
    }

    public function translationZ(): float
    {
        return $this->m[14];
    }

    /** @return float[] */
    public function toArray(): array
    {
        return $this->m;
    }

    public function __toString(): string
    {
        $m = $this->m;
        return sprintf(
            "Mat4([%.3f, %.3f, %.3f, %.3f], [%.3f, %.3f, %.3f, %.3f], [%.3f, %.3f, %.3f, %.3f], [%.3f, %.3f, %.3f, %.3f])",
            $m[0], $m[4], $m[8],  $m[12],
            $m[1], $m[5], $m[9],  $m[13],
            $m[2], $m[6], $m[10], $m[14],
            $m[3], $m[7], $m[11], $m[15],
        );
    }
}
