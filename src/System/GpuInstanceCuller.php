<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * GPU instance culling (shader plan 9.1). A large static instance batch lives in a
 * storage buffer; per frame one compute dispatch tests every instance's bounding
 * sphere against the view frustum, packs the visible matrices to the front of an
 * output buffer and counts them into an indexed indirect-draw record. The draw then
 * comes straight from the buffers - no matrix and no count ever crosses back to PHP.
 *
 * Use draw() in place of DrawMeshInstanced::packed() for batches of a few thousand
 * instances (crowds, props, scatter); null means "draw it the CPU way".
 */
final class GpuInstanceCuller
{
    public const RESET_SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 1) in;
        layout(std430, binding = 2) buffer Args { uint a[]; };
        layout(std140, binding = 3) uniform Params { uint indexCount; uint p0; uint p1; uint p2; };
        void main() { a[0] = indexCount; a[1] = 0u; a[2] = 0u; a[3] = 0u; a[4] = 0u; }
        GLSL;

    public const CULL_SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 64) in;
        layout(std430, binding = 0) readonly buffer In { float m[]; };
        layout(std430, binding = 1) writeonly buffer Out { float o[]; };
        layout(std430, binding = 2) buffer Args { uint a[]; };
        layout(std140, binding = 3) uniform Params {
            vec4 planes[6];
            uint count; float radius; float pad0; float pad1;
        };
        void main() {
            uint i = gl_GlobalInvocationID.x;
            if (i >= count) return;
            uint b = i * 16u;
            vec3 c = vec3(m[b + 12u], m[b + 13u], m[b + 14u]);
            float s = max(length(vec3(m[b], m[b + 1u], m[b + 2u])),
                      max(length(vec3(m[b + 4u], m[b + 5u], m[b + 6u])), length(vec3(m[b + 8u], m[b + 9u], m[b + 10u]))));
            float r = radius * s;
            for (int k = 0; k < 6; k++) {
                vec4 p = planes[k];
                if (dot(p.xyz, c) + p.w < -r * length(p.xyz)) return;
            }
            uint slot = atomicAdd(a[1], 1u);
            uint d = slot * 16u;
            for (uint k = 0u; k < 16u; k++) o[d + k] = m[b + k];
        }
        GLSL;

    /** @var array<string, array{in: \VioBuffer, out: \VioBuffer, args: \VioBuffer, count: int, hash: string}> */
    private static array $batches = [];

    /** @var array<int, array{reset: \VioComputePipeline, cull: \VioComputePipeline}|false> per context */
    private static array $kernels = [];

    /** Whether the context can cull on the GPU (compute + indirect draws). */
    public static function isAvailable(\VioContext $ctx): bool
    {
        return function_exists('vio_compute_pipeline') && function_exists('vio_draw_indirect')
            && defined('VIO_FEATURE_COMPUTE') && vio_supports_feature($ctx, VIO_FEATURE_COMPUTE)
            && defined('VIO_FEATURE_INDIRECT_DRAW') && vio_supports_feature($ctx, VIO_FEATURE_INDIRECT_DRAW);
    }

    /**
     * Cull a static batch and return the draw command, or null (no GPU path).
     *
     * @param string $packed   pack('f*') of 16 floats per instance (column-major)
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes frustum planes (ax + by + cz + d >= 0 inside)
     */
    public static function draw(\VioContext $ctx, string $key, string $meshId, string $materialId, string $packed, int $count, float $meshRadius, int $indexCount, array $planes): ?DrawMeshInstanced
    {
        if ($count <= 0 || $indexCount <= 0 || count($planes) !== 6 || !self::isAvailable($ctx)) {
            return null;
        }
        $k = self::kernels($ctx);
        if ($k === null) {
            return null;
        }
        $hash = md5($packed);
        $batch = self::$batches[$key] ?? null;
        if ($batch === null || $batch['count'] !== $count || $batch['hash'] !== $hash) {
            $in = vio_storage_buffer($ctx, ['data' => $packed, 'stride' => 4]);
            $out = vio_storage_buffer($ctx, ['size' => $count * 64, 'stride' => 4]);
            $args = vio_storage_buffer($ctx, ['size' => 5 * 4, 'stride' => 4, 'indirect' => true]);
            if ($in === false || $out === false || $args === false) {
                return null;
            }
            $batch = ['in' => $in, 'out' => $out, 'args' => $args, 'count' => $count, 'hash' => $hash];
            self::$batches[$key] = $batch;
        }
        GpuParticleBaker::reserveDescriptorBlock($ctx);
        vio_compute_bind_buffer($ctx, $k['reset'], $batch['args'], 2, VIO_COMPUTE_READ | VIO_COMPUTE_WRITE);
        vio_compute_set_uniforms($ctx, $k['reset'], pack('V4', $indexCount, 0, 0, 0));
        vio_compute_dispatch($ctx, $k['reset'], 1, 1, 1, ['async' => true]);

        $params = '';
        foreach ($planes as $p) {
            $params .= pack('f4', $p[0], $p[1], $p[2], $p[3]);
        }
        $params .= pack('V', $count) . pack('f3', $meshRadius, 0.0, 0.0);
        GpuParticleBaker::reserveDescriptorBlock($ctx);
        vio_compute_bind_buffer($ctx, $k['cull'], $batch['in'], 0, VIO_COMPUTE_READ);
        vio_compute_bind_buffer($ctx, $k['cull'], $batch['out'], 1, VIO_COMPUTE_WRITE);
        vio_compute_bind_buffer($ctx, $k['cull'], $batch['args'], 2, VIO_COMPUTE_READ | VIO_COMPUTE_WRITE);
        vio_compute_set_uniforms($ctx, $k['cull'], $params);
        vio_compute_dispatch($ctx, $k['cull'], (int) ceil($count / 64), 1, 1, ['async' => true]);

        return DrawMeshInstanced::fromStorageBuffer(
            meshId: $meshId,
            materialId: $materialId,
            storageBuffer: $batch['out'],
            instanceCount: $count,
            isStatic: true,
            indirectArgs: $batch['args'],
        );
    }

    /** Forget a batch (its buffers go with it). */
    public static function forget(string $key): void
    {
        unset(self::$batches[$key]);
    }

    /** @return array{reset: \VioComputePipeline, cull: \VioComputePipeline}|null */
    private static function kernels(\VioContext $ctx): ?array
    {
        $id = spl_object_id($ctx);
        if (!array_key_exists($id, self::$kernels)) {
            $reset = vio_compute_pipeline($ctx, ['source' => self::RESET_SHADER]);
            $cull = vio_compute_pipeline($ctx, ['source' => self::CULL_SHADER]);
            self::$kernels[$id] = ($reset === false || $cull === false) ? false : ['reset' => $reset, 'cull' => $cull];
            self::$batches = [];
        }
        $k = self::$kernels[$id];
        return $k === false ? null : $k;
    }
}
