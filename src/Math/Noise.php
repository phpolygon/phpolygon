<?php

declare(strict_types=1);

namespace PHPolygon\Math;

/**
 * CPU lattice noise with fixed algorithms, pure functions, no state. Results
 * depend only on IEEE-754 double +,-,* and 32-bit integer hashing, never on
 * libm (sin/cos/pow) or the platform RNG, so they are byte-stable across
 * platforms and engine versions. Do not change a step without a new method name.
 *
 * Lattice hash: hash(ix, iy, iz, seed) with all inputs reduced mod 2^32:
 *   h = fmix32(seed ^ 0x9E3779B9)
 *   h = fmix32(h ^ mul32(ix, 0x85EBCA6B))
 *   h = fmix32(h ^ mul32(iy, 0xC2B2AE35))
 *   h = fmix32(h ^ mul32(iz, 0x27D4EB2F))
 * (2D noise uses iz = 0.) fmix32/mul32 are Rng::fmix32 / Rng::mul32.
 *
 * Interpolation fade: f(t) = t*t*t*(t*(t*6 - 15) + 10) (quintic).
 *
 *  - value2/value3: hash/4294967296 * 2 - 1 at the lattice corners, fade-lerped.
 *    Range [-1, 1].
 *  - gradient2: corner gradient chosen by (hash & 7) from the table
 *    (1,0) (-1,0) (0,1) (0,-1) (k,k) (-k,k) (k,-k) (-k,-k) with k = 0.7071067811865476;
 *    value = fade-lerp of dot(gradient, offset). The raw
 *    value is multiplied by 1.4142135623730951 and clamped to [-1, 1] so fbm
 *    sums stay in range.
 *  - gradient3: gradient chosen by (hash & 15) from the 12 cube-edge vectors of
 *    improved Perlin noise, indices 12..15 repeating 0..3 (see GRAD3); value is
 *    scaled by 1.1547005383792515 (2/sqrt(3)) and clamped to [-1, 1].
 *  - fbm2/fbm3: sum over octaves o = 0..n-1 of amp_o * noise(p * freq_o, seed_o), with
 *    freq_0 = 1, amp_0 = 1, freq_{o+1} = freq_o * lacunarity, amp_{o+1} = amp_o * gain,
 *    seed_o = ((seed & 0xFFFFFFFF) + o * 0x632BE5AB) & 0xFFFFFFFF; divided by the sum of amplitudes,
 *    so the range stays [-1, 1].
 */
final class Noise
{
    private const M = 0xFFFFFFFF;

    /** @var list<list<int>> */
    private const GRAD3 = [
        [1, 1, 0], [-1, 1, 0], [1, -1, 0], [-1, -1, 0],
        [1, 0, 1], [-1, 0, 1], [1, 0, -1], [-1, 0, -1],
        [0, 1, 1], [0, -1, 1], [0, 1, -1], [0, -1, -1],
        [1, 1, 0], [-1, 1, 0], [0, -1, 1], [0, -1, -1],
    ];

    /** Lattice hash, see class docs. Returns an unsigned 32-bit int. */
    public static function hash(int $ix, int $iy, int $iz, int $seed): int
    {
        $h = Rng::fmix32(($seed & self::M) ^ 0x9E3779B9);
        $h = Rng::fmix32($h ^ Rng::mul32($ix, 0x85EBCA6B));
        $h = Rng::fmix32($h ^ Rng::mul32($iy, 0xC2B2AE35));
        return Rng::fmix32($h ^ Rng::mul32($iz, 0x27D4EB2F));
    }

    public static function value2(float $x, float $y, int $seed = 0): float
    {
        $fx = floor($x);
        $fy = floor($y);
        $ix = (int) $fx;
        $iy = (int) $fy;
        $u = self::fade($x - $fx);
        $v = self::fade($y - $fy);
        $a = self::corner($ix, $iy, 0, $seed);
        $b = self::corner($ix + 1, $iy, 0, $seed);
        $c = self::corner($ix, $iy + 1, 0, $seed);
        $d = self::corner($ix + 1, $iy + 1, 0, $seed);
        $ab = $a + ($b - $a) * $u;
        $cd = $c + ($d - $c) * $u;
        return $ab + ($cd - $ab) * $v;
    }

    public static function value3(float $x, float $y, float $z, int $seed = 0): float
    {
        $fx = floor($x);
        $fy = floor($y);
        $fz = floor($z);
        $ix = (int) $fx;
        $iy = (int) $fy;
        $iz = (int) $fz;
        $u = self::fade($x - $fx);
        $v = self::fade($y - $fy);
        $w = self::fade($z - $fz);
        $l = [];
        for ($k = 0; $k < 2; $k++) {
            $a = self::corner($ix, $iy, $iz + $k, $seed);
            $b = self::corner($ix + 1, $iy, $iz + $k, $seed);
            $c = self::corner($ix, $iy + 1, $iz + $k, $seed);
            $d = self::corner($ix + 1, $iy + 1, $iz + $k, $seed);
            $ab = $a + ($b - $a) * $u;
            $cd = $c + ($d - $c) * $u;
            $l[$k] = $ab + ($cd - $ab) * $v;
        }
        return $l[0] + ($l[1] - $l[0]) * $w;
    }

    public static function gradient2(float $x, float $y, int $seed = 0): float
    {
        $fx = floor($x);
        $fy = floor($y);
        $ix = (int) $fx;
        $iy = (int) $fy;
        $dx = $x - $fx;
        $dy = $y - $fy;
        $u = self::fade($dx);
        $v = self::fade($dy);
        $a = self::grad2($ix, $iy, $seed, $dx, $dy);
        $b = self::grad2($ix + 1, $iy, $seed, $dx - 1.0, $dy);
        $c = self::grad2($ix, $iy + 1, $seed, $dx, $dy - 1.0);
        $d = self::grad2($ix + 1, $iy + 1, $seed, $dx - 1.0, $dy - 1.0);
        $ab = $a + ($b - $a) * $u;
        $cd = $c + ($d - $c) * $u;
        $r = ($ab + ($cd - $ab) * $v) * 1.4142135623730951;
        return $r > 1.0 ? 1.0 : ($r < -1.0 ? -1.0 : $r);
    }

    public static function gradient3(float $x, float $y, float $z, int $seed = 0): float
    {
        $fx = floor($x);
        $fy = floor($y);
        $fz = floor($z);
        $ix = (int) $fx;
        $iy = (int) $fy;
        $iz = (int) $fz;
        $dx = $x - $fx;
        $dy = $y - $fy;
        $dz = $z - $fz;
        $u = self::fade($dx);
        $v = self::fade($dy);
        $w = self::fade($dz);
        $l = [];
        for ($k = 0; $k < 2; $k++) {
            $oz = $dz - $k;
            $a = self::grad3($ix, $iy, $iz + $k, $seed, $dx, $dy, $oz);
            $b = self::grad3($ix + 1, $iy, $iz + $k, $seed, $dx - 1.0, $dy, $oz);
            $c = self::grad3($ix, $iy + 1, $iz + $k, $seed, $dx, $dy - 1.0, $oz);
            $d = self::grad3($ix + 1, $iy + 1, $iz + $k, $seed, $dx - 1.0, $dy - 1.0, $oz);
            $ab = $a + ($b - $a) * $u;
            $cd = $c + ($d - $c) * $u;
            $l[$k] = $ab + ($cd - $ab) * $v;
        }
        $r = ($l[0] + ($l[1] - $l[0]) * $w) * 1.1547005383792515;
        return $r > 1.0 ? 1.0 : ($r < -1.0 ? -1.0 : $r);
    }

    /** Fractal sum of value (gradient = false) or gradient noise, range [-1, 1]. */
    public static function fbm2(
        float $x,
        float $y,
        int $octaves = 4,
        float $lacunarity = 2.0,
        float $gain = 0.5,
        int $seed = 0,
        bool $gradient = true,
    ): float {
        $sum = 0.0;
        $norm = 0.0;
        $freq = 1.0;
        $amp = 1.0;
        for ($o = 0, $n = max(1, $octaves); $o < $n; $o++) {
            $s = (($seed & self::M) + $o * 0x632BE5AB) & self::M;
            $nv = $gradient
                ? self::gradient2($x * $freq, $y * $freq, $s)
                : self::value2($x * $freq, $y * $freq, $s);
            $sum += $amp * $nv;
            $norm += $amp;
            $freq *= $lacunarity;
            $amp *= $gain;
        }
        return $sum / $norm;
    }

    public static function fbm3(
        float $x,
        float $y,
        float $z,
        int $octaves = 4,
        float $lacunarity = 2.0,
        float $gain = 0.5,
        int $seed = 0,
        bool $gradient = true,
    ): float {
        $sum = 0.0;
        $norm = 0.0;
        $freq = 1.0;
        $amp = 1.0;
        for ($o = 0, $n = max(1, $octaves); $o < $n; $o++) {
            $s = (($seed & self::M) + $o * 0x632BE5AB) & self::M;
            $nv = $gradient
                ? self::gradient3($x * $freq, $y * $freq, $z * $freq, $s)
                : self::value3($x * $freq, $y * $freq, $z * $freq, $s);
            $sum += $amp * $nv;
            $norm += $amp;
            $freq *= $lacunarity;
            $amp *= $gain;
        }
        return $sum / $norm;
    }

    private static function fade(float $t): float
    {
        return $t * $t * $t * ($t * ($t * 6.0 - 15.0) + 10.0);
    }

    private static function corner(int $ix, int $iy, int $iz, int $seed): float
    {
        return self::hash($ix, $iy, $iz, $seed) / 4294967296.0 * 2.0 - 1.0;
    }

    private static function grad2(int $ix, int $iy, int $seed, float $dx, float $dy): float
    {
        $k = 0.7071067811865476;
        switch (self::hash($ix, $iy, 0, $seed) & 7) {
            case 0: return $dx;
            case 1: return -$dx;
            case 2: return $dy;
            case 3: return -$dy;
            case 4: return $k * $dx + $k * $dy;
            case 5: return -$k * $dx + $k * $dy;
            case 6: return $k * $dx - $k * $dy;
            default: return -$k * $dx - $k * $dy;
        }
    }

    private static function grad3(int $ix, int $iy, int $iz, int $seed, float $dx, float $dy, float $dz): float
    {
        $g = self::GRAD3[self::hash($ix, $iy, $iz, $seed) & 15];
        return $g[0] * $dx + $g[1] * $dy + $g[2] * $dz;
    }
}
