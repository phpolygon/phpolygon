<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\ParticleRenderMode;

/**
 * The canonical per-particle instance matrix of every {@see ParticleRenderMode}
 * — the CPU tier writes it directly, and the GPU kernels of
 * {@see GpuParticleBaker} (`MATRIX_GLSL`) are a line-by-line port of it, so a
 * CPU/GPU comparison stays clean.
 *
 * Column-major translate × rotate × scale, 16 floats:
 *   - Billboard: +X right, +Y up, +Z towards the camera, all scaled by size.
 *   - Stretched: +Y along the velocity, scaled to `size + speed × stretch`,
 *     +X perpendicular to velocity and view direction, +Z = X × Y (as close
 *     to the camera as the axis allows); centred `speed × stretch / 2` behind
 *     the particle so its head stays at the particle position. At rest
 *     (speed ≤ 1e-5) or moving along the view ray it is a billboard.
 *   - WorldAligned, or no camera: world axes scaled by size.
 */
final class ParticleOrientation
{
    /**
     * @param array<int, float> $out flat float[N*16] instance buffer; 16
     *                               contiguous floats are written from $base.
     */
    public static function write(
        array &$out,
        int $base,
        int $mode,
        float $px, float $py, float $pz,
        float $vx, float $vy, float $vz,
        float $size,
        float $stretch,
        float $cx, float $cy, float $cz,
        bool $hasCam,
    ): void {
        if ($mode === ParticleRenderMode::WorldAligned->code() || !$hasCam) {
            self::axisAligned($out, $base, $px, $py, $pz, $size);
            return;
        }

        $dx = $cx - $px;
        $dy = $cy - $py;
        $dz = $cz - $pz;
        $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        if ($len < 1e-6) {
            self::axisAligned($out, $base, $px, $py, $pz, $size);
            return;
        }
        $fx = $dx / $len;
        $fy = $dy / $len;
        $fz = $dz / $len;

        if ($mode === ParticleRenderMode::Stretched->code()) {
            $speed = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
            if ($speed > 1e-5) {
                $ax = $vx / $speed;
                $ay = $vy / $speed;
                $az = $vz / $speed;
                // Right = axis × view; zero when the particle flies along the view ray.
                $rx = $ay * $fz - $az * $fy;
                $ry = $az * $fx - $ax * $fz;
                $rz = $ax * $fy - $ay * $fx;
                $rlen = sqrt($rx * $rx + $ry * $ry + $rz * $rz);
                if ($rlen > 1e-4) {
                    $rx /= $rlen; $ry /= $rlen; $rz /= $rlen;
                    $nx = $ry * $az - $rz * $ay;
                    $ny = $rz * $ax - $rx * $az;
                    $nz = $rx * $ay - $ry * $ax;
                    $trail = $speed * $stretch;
                    $length = $size + $trail;
                    $back = $trail * 0.5;

                    $out[$base + 0]  = $rx * $size;   $out[$base + 1]  = $ry * $size;   $out[$base + 2]  = $rz * $size;   $out[$base + 3]  = 0.0;
                    $out[$base + 4]  = $ax * $length; $out[$base + 5]  = $ay * $length; $out[$base + 6]  = $az * $length; $out[$base + 7]  = 0.0;
                    $out[$base + 8]  = $nx * $size;   $out[$base + 9]  = $ny * $size;   $out[$base + 10] = $nz * $size;   $out[$base + 11] = 0.0;
                    $out[$base + 12] = $px - $ax * $back;
                    $out[$base + 13] = $py - $ay * $back;
                    $out[$base + 14] = $pz - $az * $back;
                    $out[$base + 15] = 1.0;
                    return;
                }
            }
        }

        if (abs($fy) > 0.999) {
            $upx = 0.0; $upy = 0.0; $upz = 1.0;
        } else {
            $upx = 0.0; $upy = 1.0; $upz = 0.0;
        }
        $rx = $upy * $fz - $upz * $fy;
        $ry = $upz * $fx - $upx * $fz;
        $rz = $upx * $fy - $upy * $fx;
        $rlen = sqrt($rx * $rx + $ry * $ry + $rz * $rz);
        if ($rlen > 1e-6) {
            $rx /= $rlen; $ry /= $rlen; $rz /= $rlen;
        }
        $uxf = $fy * $rz - $fz * $ry;
        $uyf = $fz * $rx - $fx * $rz;
        $uzf = $fx * $ry - $fy * $rx;

        $out[$base + 0]  = $rx  * $size; $out[$base + 1]  = $ry  * $size; $out[$base + 2]  = $rz  * $size; $out[$base + 3]  = 0.0;
        $out[$base + 4]  = $uxf * $size; $out[$base + 5]  = $uyf * $size; $out[$base + 6]  = $uzf * $size; $out[$base + 7]  = 0.0;
        $out[$base + 8]  = $fx  * $size; $out[$base + 9]  = $fy  * $size; $out[$base + 10] = $fz  * $size; $out[$base + 11] = 0.0;
        $out[$base + 12] = $px;          $out[$base + 13] = $py;          $out[$base + 14] = $pz;          $out[$base + 15] = 1.0;
    }

    /** @param array<int, float> $out */
    private static function axisAligned(array &$out, int $base, float $px, float $py, float $pz, float $size): void
    {
        $out[$base + 0]  = $size; $out[$base + 1]  = 0.0;   $out[$base + 2]  = 0.0;   $out[$base + 3]  = 0.0;
        $out[$base + 4]  = 0.0;   $out[$base + 5]  = $size; $out[$base + 6]  = 0.0;   $out[$base + 7]  = 0.0;
        $out[$base + 8]  = 0.0;   $out[$base + 9]  = 0.0;   $out[$base + 10] = $size; $out[$base + 11] = 0.0;
        $out[$base + 12] = $px;   $out[$base + 13] = $py;   $out[$base + 14] = $pz;   $out[$base + 15] = 1.0;
    }
}
