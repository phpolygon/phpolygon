<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Ocean;

/**
 * Ocean waves from a spectrum (shader plan 10.2, Tessendorf): a Phillips spectrum of
 * $n x $n wave vectors over a $patch metre tile, turned into height and horizontal
 * ("choppy") displacement by an inverse FFT - once, at load, for $frames moments of
 * a $loop second cycle. Every angular frequency is rounded to a multiple of 2 pi /
 * $loop, so the last frame flows into the first: the sequence loops without a seam,
 * and the tile repeats without one in space too.
 *
 * The result is an RGBA8 volume ($n x $n x $frames): r height, g displacement along
 * x, b along z, each mapped from [-range, range] to [0, 255]; a = 255. The spectrum
 * carries no physical scale (no Phillips constant): `range` is its unscaled maximum,
 * and Material::$oceanAmplitude sets the metres of the largest displacement.
 * Material::$oceanTexture plays it (the vertex stage samples it by world position
 * and time).
 */
final class OceanSpectrum
{
    /**
     * @return array{data: string, size: int, frames: int, range: float}
     */
    public static function bake(
        int $n = 64,
        float $patch = 64.0,
        float $windSpeed = 9.0,
        float $windAngle = 0.0,
        int $frames = 24,
        float $loop = 12.0,
        float $chop = 1.0,
        int $seed = 7,
    ): array {
        if ($n < 4 || ($n & ($n - 1)) !== 0) {
            throw new \InvalidArgumentException('OceanSpectrum: size must be a power of two');
        }
        $g = 9.81;
        $L = $windSpeed * $windSpeed / $g;            // largest wave from the wind
        $wx = cos($windAngle);
        $wz = sin($windAngle);
        $base = 2.0 * M_PI / $loop;
        mt_srand($seed);
        $gauss = static function (): array {
            $u1 = max(1e-9, mt_rand() / mt_getrandmax());
            $u2 = mt_rand() / mt_getrandmax();
            $r = sqrt(-2.0 * log($u1));
            return [$r * cos(2.0 * M_PI * $u2), $r * sin(2.0 * M_PI * $u2)];
        };
        // h0(k) for every wave vector, and its quantised angular frequency.
        $h0 = [];
        $omega = [];
        $kvec = [];
        for ($m = 0; $m < $n; $m++) {
            for ($l = 0; $l < $n; $l++) {
                $kx = 2.0 * M_PI * ($l - $n / 2) / $patch;
                $kz = 2.0 * M_PI * ($m - $n / 2) / $patch;
                $k = sqrt($kx * $kx + $kz * $kz);
                $i = $m * $n + $l;
                $kvec[$i] = [$kx, $kz, $k];
                if ($k < 1e-6) {
                    $h0[$i] = [0.0, 0.0];
                    $omega[$i] = 0.0;
                    continue;
                }
                $kdotw = ($kx * $wx + $kz * $wz) / $k;
                $ph = exp(-1.0 / ($k * $L) ** 2) / $k ** 4 * $kdotw * $kdotw;
                // Small waves die out, waves against the wind are weak.
                $ph *= exp(-($k * $L * 0.001) ** 2) * ($kdotw < 0.0 ? 0.25 : 1.0);
                [$a, $b] = $gauss();
                $s = sqrt($ph / 2.0);
                $h0[$i] = [$a * $s, $b * $s];
                $omega[$i] = round(sqrt($g * $k) / $base) * $base;
            }
        }
        $frameData = [];
        $range = 1e-6;
        for ($f = 0; $f < $frames; $f++) {
            $t = $loop * $f / $frames;
            $H = [];
            $DX = [];
            $DZ = [];
            for ($m = 0; $m < $n; $m++) {
                for ($l = 0; $l < $n; $l++) {
                    $i = $m * $n + $l;
                    $j = ((($n - $m) % $n) * $n) + (($n - $l) % $n);   // -k
                    $c = cos($omega[$i] * $t);
                    $s = sin($omega[$i] * $t);
                    // h(k,t) = h0(k) e^{iwt} + conj(h0(-k)) e^{-iwt}
                    $re = $h0[$i][0] * $c - $h0[$i][1] * $s + $h0[$j][0] * $c - $h0[$j][1] * $s;
                    $im = $h0[$i][0] * $s + $h0[$i][1] * $c - ($h0[$j][0] * $s + $h0[$j][1] * $c);
                    $H[$i] = [$re, $im];
                    [$kx, $kz, $k] = $kvec[$i];
                    $ux = $k > 1e-6 ? $kx / $k : 0.0;
                    $uz = $k > 1e-6 ? $kz / $k : 0.0;
                    // D(k) = -i k/|k| h(k)
                    $DX[$i] = [$ux * $im, -$ux * $re];
                    $DZ[$i] = [$uz * $im, -$uz * $re];
                }
            }
            $h = self::ifft2($H, $n);
            $dx = self::ifft2($DX, $n);
            $dz = self::ifft2($DZ, $n);
            for ($i = 0, $c = $n * $n; $i < $c; $i++) {
                // (-1)^(l+m): the spectrum was centred on k = 0.
                $sign = ((intdiv($i, $n) + $i % $n) & 1) ? -1.0 : 1.0;
                $h[$i] *= $sign;
                $dx[$i] *= $sign * $chop;
                $dz[$i] *= $sign * $chop;
                $range = max($range, abs($h[$i]), abs($dx[$i]), abs($dz[$i]));
            }
            $frameData[] = [$h, $dx, $dz];
        }
        $out = '';
        foreach ($frameData as [$h, $dx, $dz]) {
            $bytes = [];
            for ($i = 0, $c = $n * $n; $i < $c; $i++) {
                $bytes[] = (int) round(($h[$i] / $range * 0.5 + 0.5) * 255.0);
                $bytes[] = (int) round(($dx[$i] / $range * 0.5 + 0.5) * 255.0);
                $bytes[] = (int) round(($dz[$i] / $range * 0.5 + 0.5) * 255.0);
                $bytes[] = 255;
            }
            $out .= pack('C*', ...$bytes);
        }
        return ['data' => $out, 'size' => $n, 'frames' => $frames, 'range' => $range];
    }

    /**
     * The baked volume as a GPU texture (repeating in space and time), to register
     * under the id a Material::$oceanTexture names (VioRenderer3D::registerGpuTexture).
     *
     * @param array{data: string, size: int, frames: int, range: float} $baked
     */
    public static function upload(\VioContext $ctx, array $baked): ?\VioTexture
    {
        $tex = vio_texture_3d($ctx, [
            'data' => $baked['data'],
            'width' => $baked['size'],
            'height' => $baked['size'],
            'depth' => $baked['frames'],
            'filter' => VIO_FILTER_LINEAR,
            'wrap' => VIO_WRAP_REPEAT,
        ]);
        return $tex instanceof \VioTexture ? $tex : null;
    }

    /**
     * Inverse 2D FFT of $n x $n complex values (row-major), real part out.
     *
     * @param array<int, array{0: float, 1: float}> $a
     * @return list<float>
     */
    private static function ifft2(array $a, int $n): array
    {
        $re = [];
        $im = [];
        foreach ($a as $i => [$r, $m]) {
            $re[$i] = $r;
            $im[$i] = $m;
        }
        for ($row = 0; $row < $n; $row++) {
            self::fft1($re, $im, $row * $n, 1, $n);
        }
        for ($col = 0; $col < $n; $col++) {
            self::fft1($re, $im, $col, $n, $n);
        }
        $out = [];
        for ($i = 0, $c = $n * $n; $i < $c; $i++) {
            $out[] = $re[$i];
        }
        return $out;
    }

    /**
     * In-place radix-2 inverse FFT (no 1/n: the spectrum carries the scale) of $n
     * values at $offset + k * $stride.
     *
     * @param array<int, float> $re
     * @param array<int, float> $im
     */
    private static function fft1(array &$re, array &$im, int $offset, int $stride, int $n): void
    {
        // Bit reversal.
        for ($i = 1, $j = 0; $i < $n; $i++) {
            $bit = $n >> 1;
            for (; $j & $bit; $bit >>= 1) {
                $j ^= $bit;
            }
            $j ^= $bit;
            if ($i < $j) {
                $a = $offset + $i * $stride;
                $b = $offset + $j * $stride;
                [$re[$a], $re[$b]] = [$re[$b], $re[$a]];
                [$im[$a], $im[$b]] = [$im[$b], $im[$a]];
            }
        }
        for ($len = 2; $len <= $n; $len <<= 1) {
            $ang = 2.0 * M_PI / $len;      // + : inverse transform
            $wr = cos($ang);
            $wi = sin($ang);
            for ($i = 0; $i < $n; $i += $len) {
                $cr = 1.0;
                $ci = 0.0;
                $half = $len >> 1;
                for ($k = 0; $k < $half; $k++) {
                    $a = $offset + ($i + $k) * $stride;
                    $b = $offset + ($i + $k + $half) * $stride;
                    $tr = $re[$b] * $cr - $im[$b] * $ci;
                    $ti = $re[$b] * $ci + $im[$b] * $cr;
                    $re[$b] = $re[$a] - $tr;
                    $im[$b] = $im[$a] - $ti;
                    $re[$a] += $tr;
                    $im[$a] += $ti;
                    $nr = $cr * $wr - $ci * $wi;
                    $ci = $cr * $wi + $ci * $wr;
                    $cr = $nr;
                }
            }
        }
    }
}
