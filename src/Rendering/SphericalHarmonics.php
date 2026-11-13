<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;

/**
 * Second-order (nine coefficient) spherical harmonics of the light arriving from every direction, for diffuse
 * ambient light that follows an environment (the sky cube) instead of one flat colour. Pure CPU: project radiance
 * samples, then ask for the irradiance coefficients the mesh shader evaluates (`u_sky_sh0..8`).
 *
 * Basis order: Y00, Y1-1 (y), Y10 (z), Y11 (x), Y2-2 (xy), Y2-1 (yz), Y20 (3z^2 - 1), Y21 (xz), Y22 (x^2 - y^2).
 */
final class SphericalHarmonics
{
    /** @var list<array{0: float, 1: float, 2: float}> nine RGB coefficients of the radiance */
    private array $radiance;

    /** @param list<array{0: float, 1: float, 2: float}> $radiance */
    private function __construct(array $radiance)
    {
        $this->radiance = $radiance;
    }

    /**
     * Project samples: each is [dx, dy, dz, r, g, b] with a unit direction, weighted by $solidAngle (the sum of the
     * weights should be 4 pi; pass null to normalise them equally).
     *
     * @param iterable<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $samples
     */
    public static function project(iterable $samples, ?float $solidAngle = null): self
    {
        $c = array_fill(0, 9, [0.0, 0.0, 0.0]);
        $n = 0;
        foreach ($samples as [$x, $y, $z, $r, $g, $b]) {
            $basis = self::basis($x, $y, $z);
            for ($i = 0; $i < 9; $i++) {
                $c[$i][0] += $r * $basis[$i];
                $c[$i][1] += $g * $basis[$i];
                $c[$i][2] += $b * $basis[$i];
            }
            $n++;
        }
        $w = $n > 0 ? ($solidAngle ?? 4.0 * M_PI) / $n : 0.0;
        foreach ($c as $i => $v) {
            $c[$i] = [$v[0] * $w, $v[1] * $w, $v[2] * $w];
        }

        return new self($c);
    }

    /**
     * From the six faces of a cube (RGBA8, top-down rows, faces +X -X +Y -Y +Z -Z, as VioEnvironmentCubemap renders them),
     * sampling every $stride-th pixel. Texels are weighted by their solid angle. Colours are the 8-bit values as 0..1
     * (the cube is an LDR image: the sun disc and glare are clipped, the sky's hue and gradient are what it carries).
     *
     * @param list<string> $faces
     */
    public static function fromCubeFaces(array $faces, int $size, int $stride = 8, bool $flipClipY = false): self
    {
        $inv = VioEnvironmentCubemap::faceInverseViewProjections($flipClipY);
        $weightSum = 0.0;
        $weighted = [];
        for ($f = 0; $f < 6; $f++) {
            $m = (new Mat4($inv[$f]));
            for ($row = intdiv($stride, 2); $row < $size; $row += $stride) {
                for ($col = intdiv($stride, 2); $col < $size; $col += $stride) {
                    $nx = ($col + 0.5) / $size * 2.0 - 1.0;
                    // Row 0 is the top of the image; with the flip applied the face image is the same on every backend.
                    $ny = 1.0 - ($row + 0.5) / $size * 2.0;
                    $d = $m->multiplyVec4(new \PHPolygon\Math\Vec4($nx, $ny, 1.0, 1.0));
                    $len = sqrt($d->x * $d->x + $d->y * $d->y + $d->z * $d->z);
                    if ($len < 1e-9) {
                        continue;
                    }
                    $o = ($row * $size + $col) * 4;
                    // Solid angle of a cube texel: 1 / (1 + u^2 + v^2)^1.5 of the face-plane area.
                    $sa = 1.0 / (($nx * $nx + $ny * $ny + 1.0) ** 1.5);
                    $weightSum += $sa;
                    $weighted[] = [$d->x / $len, $d->y / $len, $d->z / $len, ord($faces[$f][$o]) / 255.0, ord($faces[$f][$o + 1]) / 255.0, ord($faces[$f][$o + 2]) / 255.0, $sa];
                }
            }
        }
        $c = array_fill(0, 9, [0.0, 0.0, 0.0]);
        foreach ($weighted as [$x, $y, $z, $r, $g, $b, $sa]) {
            $basis = self::basis($x, $y, $z);
            $w = $sa / max($weightSum, 1e-12) * 4.0 * M_PI;
            for ($i = 0; $i < 9; $i++) {
                $c[$i][0] += $r * $basis[$i] * $w;
                $c[$i][1] += $g * $basis[$i] * $w;
                $c[$i][2] += $b * $basis[$i] * $w;
            }
        }
        return new self($c);
    }

    /**
     * The coefficients the shader evaluates: the radiance's, times the cosine-lobe convolution (pi, 2 pi / 3, pi / 4 for
     * the bands) and $gain: the shader's sum over the basis at a normal n is the irradiance E(n) / pi * pi * gain, i.e.
     * a uniform radiance L gives L * pi * gain, the same scale as the flat ambient colour times its intensity.
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public function irradianceCoefficients(float $gain = 1.0): array
    {
        $a = [M_PI, 2.0 * M_PI / 3.0, 2.0 * M_PI / 3.0, 2.0 * M_PI / 3.0, M_PI / 4.0, M_PI / 4.0, M_PI / 4.0, M_PI / 4.0, M_PI / 4.0];
        $out = [];
        foreach ($this->radiance as $i => $v) {
            $k = $a[$i] * $gain;
            $out[] = [$v[0] * $k, $v[1] * $k, $v[2] * $k];
        }

        return $out;
    }

    /**
     * The ambient colour at normal n from these coefficients (what the shader computes), clamped at 0.
     *
     * @param list<array{0: float, 1: float, 2: float}> $coefficients from irradianceCoefficients()
     * @return array{0: float, 1: float, 2: float}
     */
    public static function evaluate(array $coefficients, float $x, float $y, float $z): array
    {
        $b = self::basis($x, $y, $z);
        $r = $g = $bl = 0.0;
        foreach ($coefficients as $i => $v) {
            $r += $v[0] * $b[$i];
            $g += $v[1] * $b[$i];
            $bl += $v[2] * $b[$i];
        }

        return [max(0.0, $r), max(0.0, $g), max(0.0, $bl)];
    }

    /** @return list<float> */
    private static function basis(float $x, float $y, float $z): array
    {
        return [
            0.282095,
            0.488603 * $y, 0.488603 * $z, 0.488603 * $x,
            1.092548 * $x * $y, 1.092548 * $y * $z, 0.315392 * (3.0 * $z * $z - 1.0), 1.092548 * $x * $z, 0.546274 * ($x * $x - $y * $y),
        ];
    }
}
