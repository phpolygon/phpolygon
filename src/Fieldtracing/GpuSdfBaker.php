<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing;

/**
 * GPU offload for box-occluder SDF voxelization (the fieldtracing volume bake).
 * Given a set of occluder boxes and a grid, it dispatches a compute shader that
 * writes the flat float distance grid, reads it back, and returns the same
 * {@code list<float>} the CPU path produces — so callers are interchangeable and
 * the probe/reflection bakers (which consume the raw floats) are untouched.
 *
 * Everything is best-effort: if the php-vio build has no compute primitive, the
 * backend doesn't support VIO_FEATURE_COMPUTE, or anything throws, {@see tryFill}
 * returns null and the caller falls back to its CPU path. A GPU failure must
 * NEVER fail the bake.
 *
 * The per-cell distance math is a Quilez box SDF; keep it identical to whatever
 * CPU path it stands in for so the two agree to float precision.
 */
final class GpuSdfBaker
{
    /** Threads per workgroup — must match the shader's local_size_x. */
    private const LOCAL_SIZE = 64;

    /** Compiled compute pipeline, cached so the (expensive) shader compile happens
     *  once — warmed during the splash via {@see warm()}, reused by every bake. */
    private static mixed $pipeline = null;

    /**
     * Pre-compile + cache the compute pipeline (the shader compile is the
     * dominant cost). Call once during the splash so the world-load bake is just
     * bind+dispatch+readback. Safe to call repeatedly; returns false when compute
     * is unavailable.
     */
    public static function warm(\VioContext $ctx): bool
    {
        if (!self::isAvailable($ctx)) {
            return false;
        }
        if (self::$pipeline === null) {
            $p = vio_compute_pipeline($ctx, ['source' => self::SHADER]);
            if ($p === false) {
                return false;
            }
            self::$pipeline = $p;
        }
        return true;
    }

    /**
     * GLSL compute shader: one thread per grid cell, min over building boxes of
     * the Quilez box SDF. Distinct bindings (0,1,2) so the source is valid on every
     * backend (OpenGL SSBO slots and Vulkan descriptor-set bindings must be unique;
     * the D3D backends map binding→HLSL register data-driven from reflection).
     *   binding 0 = boxes  (readonly  SSBO, raw float[]: 6 per box cx,cy,cz,hx,hy,hz)
     *   binding 1 = dist   (writeonly SSBO, raw float[]: one per cell)
     *   binding 2 = Params (UBO: nx,ny,nz,boxCount:int; minx,miny,minz,cell:float)
     */
    public const SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 64, local_size_y = 1, local_size_z = 1) in;

        layout(std430, binding = 0) readonly buffer Boxes  { float boxes[]; };
        layout(std430, binding = 1) writeonly buffer OutD  { float dist[];  };
        layout(std140, binding = 2) uniform Params {
            int nx; int ny; int nz; int boxCount;
            float minx; float miny; float minz; float cell;
        };

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            uint total = uint(nx) * uint(ny) * uint(nz);
            if (gid >= total) return;

            uint ix = gid % uint(nx);
            uint iy = (gid / uint(nx)) % uint(ny);
            uint iz = gid / (uint(nx) * uint(ny));

            vec3 p = vec3(minx + float(ix) * cell,
                          miny + float(iy) * cell,
                          minz + float(iz) * cell);

            float best = 1.0e9;
            for (int b = 0; b < boxCount; b++) {
                vec3 c = vec3(boxes[b * 6 + 0], boxes[b * 6 + 1], boxes[b * 6 + 2]);
                vec3 h = vec3(boxes[b * 6 + 3], boxes[b * 6 + 4], boxes[b * 6 + 5]);
                vec3 q = abs(p - c) - h;
                float outside = length(max(q, 0.0));
                float inside  = min(max(q.x, max(q.y, q.z)), 0.0);
                float d = outside + inside;
                best = min(best, d);
            }
            dist[gid] = best;
        }
        GLSL;

    /**
     * Compute the flat distance grid on the GPU, or null when unavailable.
     *
     * @param \VioContext $ctx   the active rendering context
     * @param list<array{0:float,1:float,2:float,3:float,4:float,5:float}> $boxes
     * @return list<float>|null  length nx*ny*nz, flat index ix + iy*nx + iz*nx*ny
     */
    public static function tryFill(
        \VioContext $ctx,
        array $boxes,
        int $nx, int $ny, int $nz,
        float $minx, float $miny, float $minz, float $cell,
    ): ?array {
        if (!self::isAvailable($ctx)) {
            return null;
        }
        if ($boxes === []) {
            return null; // nothing to occlude — let the caller short-circuit on CPU
        }

        try {
            // Reuse the splash-warmed pipeline; compile lazily if warm() never ran.
            $pipeline = self::$pipeline ?? vio_compute_pipeline($ctx, ['source' => self::SHADER]);
            if ($pipeline === false) {
                return null;
            }
            self::$pipeline = $pipeline;

            // Boxes → flat float SRV (6 floats each).
            $flat = [];
            foreach ($boxes as $b) {
                $flat[] = $b[0]; $flat[] = $b[1]; $flat[] = $b[2];
                $flat[] = $b[3]; $flat[] = $b[4]; $flat[] = $b[5];
            }
            // Flat float[] SSBO (the shader indexes boxes[b*6+k]) → raw view, stride 4.
            $boxBuf = vio_storage_buffer($ctx, ['data' => pack('f*', ...$flat), 'stride' => 4]);

            $cellCount = $nx * $ny * $nz;
            $outBuf = vio_storage_buffer($ctx, ['size' => $cellCount * 4, 'stride' => 4]);
            if ($boxBuf === false || $outBuf === false) {
                return null;
            }

            // Params: 4×int32 then 4×float32 = 32 bytes, no padding (all 4-byte
            // scalars pack tightly in std140 and in the HLSL cbuffer: nx,ny,nz,
            // boxCount at c0.xyzw, minx,miny,minz,cell at c1.xyzw).
            $params = pack('l4', $nx, $ny, $nz, count($boxes))
                    . pack('f4', $minx, $miny, $minz, $cell);
            vio_compute_set_uniforms($ctx, $pipeline, $params);

            vio_compute_bind_buffer($ctx, $pipeline, $boxBuf, 0, VIO_COMPUTE_READ);
            vio_compute_bind_buffer($ctx, $pipeline, $outBuf, 1, VIO_COMPUTE_WRITE);

            $groups = intdiv($cellCount + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);
            vio_compute_dispatch($ctx, $pipeline, $groups, 1, 1);

            $bytes = vio_storage_buffer_read($ctx, $outBuf);
            if ($bytes === false || strlen($bytes) < $cellCount * 4) {
                return null;
            }

            /** @var list<float> $data */
            $data = array_values(unpack('f*', substr($bytes, 0, $cellCount * 4)));
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** True when the context can run the compute primitive. */
    public static function isAvailable(\VioContext $ctx): bool
    {
        return function_exists('vio_compute_pipeline')
            && defined('VIO_FEATURE_COMPUTE')
            && vio_supports_feature($ctx, VIO_FEATURE_COMPUTE);
    }
}
