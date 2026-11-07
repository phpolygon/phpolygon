<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * GPU animation of rigid-part figures (shader plan 6.3, "GPU skinning" for figures
 * built from separate limb meshes). Each figure has a root matrix and a walk phase;
 * each part hangs at a pivot of the figure and swings about an axis. One compute
 * dispatch per part writes the part's instance matrix for every figure into its own
 * buffer, drawn straight from there - a crowd of thousands animates without one
 * matrix built in PHP. step() is the same math on the CPU (fallback and reference).
 *
 * Part i of a figure: M = root * T(pivot) * R(axis, amplitude * sin(2 pi (phase +
 * phaseOffset))) * rest, where rest places the part mesh relative to its pivot.
 */
final class GpuPartAnimator
{
    public const SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 64) in;
        layout(std430, binding = 0) readonly buffer Figures { float f[]; };   // 20 floats: root (16), phase, speed, pad, pad
        layout(std430, binding = 1) writeonly buffer Out { float o[]; };
        layout(std140, binding = 2) uniform Params {
            mat4  rest;
            vec4  pivot;      // xyz pivot, w amplitude (radians)
            vec4  axis;       // xyz swing axis, w phase offset (cycles)
            uint  count; float time; float pad0; float pad1;
        };
        mat4 rotation(vec3 a, float ang) {
            a = normalize(a);
            float c = cos(ang), s = sin(ang), t = 1.0 - c;
            return mat4(t*a.x*a.x + c,     t*a.x*a.y + s*a.z, t*a.x*a.z - s*a.y, 0.0,
                        t*a.x*a.y - s*a.z, t*a.y*a.y + c,     t*a.y*a.z + s*a.x, 0.0,
                        t*a.x*a.z + s*a.y, t*a.y*a.z - s*a.x, t*a.z*a.z + c,     0.0,
                        0.0, 0.0, 0.0, 1.0);
        }
        void main() {
            uint i = gl_GlobalInvocationID.x;
            if (i >= count) return;
            uint b = i * 20u;
            mat4 root = mat4(f[b], f[b+1u], f[b+2u], f[b+3u], f[b+4u], f[b+5u], f[b+6u], f[b+7u],
                             f[b+8u], f[b+9u], f[b+10u], f[b+11u], f[b+12u], f[b+13u], f[b+14u], f[b+15u]);
            float phase = f[b + 16u] + time * f[b + 17u];
            float ang = pivot.w * sin(6.2831853 * (phase + axis.w));
            mat4 t = mat4(1.0); t[3] = vec4(pivot.xyz, 1.0);
            mat4 m = root * t * rotation(axis.xyz, ang) * rest;
            uint d = i * 16u;
            for (int c = 0; c < 4; c++) { o[d + uint(c) * 4u] = m[c].x; o[d + uint(c) * 4u + 1u] = m[c].y; o[d + uint(c) * 4u + 2u] = m[c].z; o[d + uint(c) * 4u + 3u] = m[c].w; }
        }
        GLSL;

    /** @var array<int, \VioComputePipeline|false> */
    private static array $kernels = [];

    /**
     * Matrices of one part for every figure on the CPU (fallback / reference).
     *
     * @param list<float> $figures 20 floats per figure (root, phase, speed, 0, 0)
     * @param list<float> $rest    16 floats, column-major
     * @param array{0: float, 1: float, 2: float} $pivot
     * @param array{0: float, 1: float, 2: float} $axis
     * @return list<float> 16 floats per figure
     */
    public static function step(array $figures, array $rest, array $pivot, array $axis, float $amplitude, float $phaseOffset, float $time): array
    {
        $out = [];
        $n = intdiv(count($figures), 20);
        $l = sqrt($axis[0] ** 2 + $axis[1] ** 2 + $axis[2] ** 2) ?: 1.0;
        [$ax, $ay, $az] = [$axis[0] / $l, $axis[1] / $l, $axis[2] / $l];
        for ($i = 0; $i < $n; $i++) {
            $b = $i * 20;
            $root = array_slice($figures, $b, 16);
            $phase = $figures[$b + 16] + $time * $figures[$b + 17];
            $ang = $amplitude * sin(6.2831853 * ($phase + $phaseOffset));
            $c = cos($ang); $s = sin($ang); $t = 1.0 - $c;
            $r = [
                $t * $ax * $ax + $c, $t * $ax * $ay + $s * $az, $t * $ax * $az - $s * $ay, 0.0,
                $t * $ax * $ay - $s * $az, $t * $ay * $ay + $c, $t * $ay * $az + $s * $ax, 0.0,
                $t * $ax * $az + $s * $ay, $t * $ay * $az - $s * $ax, $t * $az * $az + $c, 0.0,
                0.0, 0.0, 0.0, 1.0,
            ];
            $tr = [1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, $pivot[0], $pivot[1], $pivot[2], 1.0];
            $m = self::mul(self::mul(self::mul($root, $tr), $r), $rest);
            array_push($out, ...$m);
        }
        return $out;
    }

    /**
     * Animate one part on the GPU and return its draw, or null (no compute).
     *
     * @param list<float> $rest
     * @param array{0: float, 1: float, 2: float} $pivot
     * @param array{0: float, 1: float, 2: float} $axis
     */
    public static function draw(\VioContext $ctx, \VioBuffer $figures, int $count, \VioBuffer $out, string $meshId, string $materialId,
                                array $rest, array $pivot, array $axis, float $amplitude, float $phaseOffset, float $time): ?DrawMeshInstanced
    {
        if ($count <= 0 || !function_exists('vio_compute_pipeline') || !defined('VIO_FEATURE_COMPUTE') || !vio_supports_feature($ctx, VIO_FEATURE_COMPUTE)) {
            return null;
        }
        $id = spl_object_id($ctx);
        self::$kernels[$id] ??= vio_compute_pipeline($ctx, ['source' => self::SHADER]);
        $k = self::$kernels[$id];
        if ($k === false) {
            return null;
        }
        GpuParticleBaker::reserveDescriptorBlock($ctx);
        vio_compute_bind_buffer($ctx, $k, $figures, 0, VIO_COMPUTE_READ);
        vio_compute_bind_buffer($ctx, $k, $out, 1, VIO_COMPUTE_WRITE);
        vio_compute_set_uniforms($ctx, $k, pack('f16', ...$rest)
            . pack('f4', $pivot[0], $pivot[1], $pivot[2], $amplitude)
            . pack('f4', $axis[0], $axis[1], $axis[2], $phaseOffset)
            . pack('V', $count) . pack('f3', $time, 0.0, 0.0));
        vio_compute_dispatch($ctx, $k, (int) ceil($count / 64), 1, 1, ['async' => true]);
        return DrawMeshInstanced::fromStorageBuffer(meshId: $meshId, materialId: $materialId, storageBuffer: $out, instanceCount: $count);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float>
     */
    private static function mul(array $a, array $b): array
    {
        $r = [];
        for ($c = 0; $c < 4; $c++) {
            for ($row = 0; $row < 4; $row++) {
                $r[$c * 4 + $row] = $a[$row] * $b[$c * 4] + $a[4 + $row] * $b[$c * 4 + 1] + $a[8 + $row] * $b[$c * 4 + 2] + $a[12 + $row] * $b[$c * 4 + 3];
            }
        }
        return $r;
    }
}
