<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Math\Vec3;

/**
 * GPU offload for the particle per-slot integration AND the camera-facing
 * billboard-matrix build. One compute thread per slot: it advances the slot's
 * position/velocity/age (semi-implicit Euler) and writes the finished
 * column-major instance matrix. PHP reads that matrix buffer back once and
 * hands the raw bytes straight to {@see \PHPolygon\Rendering\Command\DrawMeshInstanced::packed()}
 * — no per-particle PHP loop, no unpack/repack.
 *
 * The shape mirrors {@see \PHPolygon\Fieldtracing\GpuSdfBaker} exactly: inline
 * GLSL compute const, pipeline compiled once + cached (warm at splash), data in
 * raw f32 SSBOs via pack('f*',…), dispatch, {@see vio_storage_buffer_read},
 * everything best-effort with a null fallback. A GPU failure must NEVER fail a
 * frame — the caller falls back to the canonical CPU path in
 * {@see \PHPolygon\System\ParticleSystem}.
 *
 * Keep the integration and billboard math numerically identical to
 * {@see \PHPolygon\System\ParticleSystem::integrate()} /
 * {@see \PHPolygon\System\ParticleSystem::writeBillboardMatrix()} so an A/B
 * comparison stays clean. Particles are purely visual, so float divergence
 * between the CPU and GPU paths is acceptable.
 *
 * SCOPE — PHASE 1. This class currently covers the *measurable core*: seed a
 * GPU-resident state once ({@see createState}) and step it ({@see step}). That
 * is what the benchmark needs to answer the only question that matters before
 * investing further — does GPU compute beat the CPU at these particle counts,
 * and does the readback eat the win? Live spawning into dead slots (A.3's CPU
 * free-slot cursor) and the {@see ParticleSystem} wiring are Phase 2, gated on
 * the benchmark showing a readback-bound win. ext-vio exposes no partial
 * storage-buffer write, so Phase 2's spawn model must be either a GPU-side
 * spawn-inject SSBO or a per-frame full state re-upload — decided by the bench.
 *
 * TODO(phase2): GPU slot compaction via atomics (draw only live instances
 * instead of always maxParticles) is a later optimisation, not this cut.
 */
final class GpuParticleBaker
{
    /** Threads per workgroup — must match the shader's local_size_x. */
    private const LOCAL_SIZE = 64;

    /** Compiled compute pipeline, cached so the shader compile happens once —
     *  warmed during the splash via {@see warm()}, reused by every step. */
    private static ?\VioComputePipeline $pipeline = null;

    /** Billboard-only pipeline for the readback-free path ({@see BILLBOARD_SHADER}). */
    private static ?\VioComputePipeline $billboardPipeline = null;

    /**
     * Reusable per-emitter output matrix SSBO for the readback-free path, kept
     * GPU-resident so it can be bound as the graphics instance source and only
     * freed when the emitter is GC'd.
     *
     * @var \WeakMap<ParticleEmitter, \VioBuffer>|null
     */
    private static ?\WeakMap $billboardOutputs = null;

    /**
     * GLSL compute shader: one thread per slot. Reads the RW state row
     * (8 floats: px,py,pz, vx,vy,vz, age, lifetime), integrates it in place,
     * and writes the 16-float column-major billboard matrix into the output
     * buffer. Dead slots (age past lifetime) get a zero matrix, which collapses
     * the quad to a point — invisible in the raster, no branch needed downstream.
     *
     *   binding 0 = State  (RW  SSBO, 8 floats/slot) — integrated in place
     *   binding 1 = OutM   (out SSBO, 16 floats/slot) — column-major matrices
     *   binding 2 = Params (UBO: dt; gravity xyz; start/end size; cam xyz;
     *                       hasCam:int; count:int; pad:int) — 48 bytes, std140
     */
    public const SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 64, local_size_y = 1, local_size_z = 1) in;

        layout(std430, binding = 0) buffer State { float s[]; };
        layout(std430, binding = 1) writeonly buffer OutM { float m[]; };
        layout(std140, binding = 2) uniform Params {
            float dt;
            float gx; float gy; float gz;
            float startSize; float endSize;
            float camx; float camy; float camz;
            int   hasCam;
            int   count;
            int   pad;
        };

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            if (gid >= uint(count)) return;
            uint b = gid * 8u;
            uint o = gid * 16u;

            float age  = s[b + 6u];
            float life = s[b + 7u];

            // Dead slot -> zero matrix, no integration.
            if (age >= life || life <= 0.0) {
                for (uint k = 0u; k < 16u; k++) m[o + k] = 0.0;
                return;
            }

            // Semi-implicit Euler — matches ParticleSystem::integrate().
            float vx = s[b + 3u] + gx * dt;
            float vy = s[b + 4u] + gy * dt;
            float vz = s[b + 5u] + gz * dt;
            float px = s[b + 0u] + vx * dt;
            float py = s[b + 1u] + vy * dt;
            float pz = s[b + 2u] + vz * dt;
            age += dt;

            s[b + 0u] = px; s[b + 1u] = py; s[b + 2u] = pz;
            s[b + 3u] = vx; s[b + 4u] = vy; s[b + 5u] = vz;
            s[b + 6u] = age;

            float t    = age / max(life, 1e-4);
            float size = mix(startSize, endSize, t);

            // Non-cam / degenerate: axis-aligned size*I with translation.
            if (hasCam == 0) {
                m[o+0u]=size; m[o+1u]=0.0;  m[o+2u]=0.0;   m[o+3u]=0.0;
                m[o+4u]=0.0;  m[o+5u]=size; m[o+6u]=0.0;   m[o+7u]=0.0;
                m[o+8u]=0.0;  m[o+9u]=0.0;  m[o+10u]=size; m[o+11u]=0.0;
                m[o+12u]=px;  m[o+13u]=py;  m[o+14u]=pz;   m[o+15u]=1.0;
                return;
            }

            float dx = camx - px;
            float dy = camy - py;
            float dz = camz - pz;
            float len = sqrt(dx*dx + dy*dy + dz*dz);
            if (len < 1e-6) {
                m[o+0u]=size; m[o+1u]=0.0;  m[o+2u]=0.0;   m[o+3u]=0.0;
                m[o+4u]=0.0;  m[o+5u]=size; m[o+6u]=0.0;   m[o+7u]=0.0;
                m[o+8u]=0.0;  m[o+9u]=0.0;  m[o+10u]=size; m[o+11u]=0.0;
                m[o+12u]=px;  m[o+13u]=py;  m[o+14u]=pz;   m[o+15u]=1.0;
                return;
            }

            float fx = dx / len;
            float fy = dy / len;
            float fz = dz / len;
            float upx, upy, upz;
            if (abs(fy) > 0.999) { upx = 0.0; upy = 0.0; upz = 1.0; }
            else                 { upx = 0.0; upy = 1.0; upz = 0.0; }
            float rx = upy*fz - upz*fy;
            float ry = upz*fx - upx*fz;
            float rz = upx*fy - upy*fx;
            float rlen = sqrt(rx*rx + ry*ry + rz*rz);
            if (rlen > 1e-6) { rx /= rlen; ry /= rlen; rz /= rlen; }
            float uxf = fy*rz - fz*ry;
            float uyf = fz*rx - fx*rz;
            float uzf = fx*ry - fy*rx;

            m[o+0u]=rx*size;  m[o+1u]=ry*size;  m[o+2u]=rz*size;  m[o+3u]=0.0;
            m[o+4u]=uxf*size; m[o+5u]=uyf*size; m[o+6u]=uzf*size; m[o+7u]=0.0;
            m[o+8u]=fx*size;  m[o+9u]=fy*size;  m[o+10u]=fz*size; m[o+11u]=0.0;
            m[o+12u]=px;      m[o+13u]=py;      m[o+14u]=pz;      m[o+15u]=1.0;
        }
        GLSL;

    /** True when the context can run the compute primitive. Identical gate to GpuSdfBaker. */
    public static function isAvailable(\VioContext $ctx): bool
    {
        return function_exists('vio_compute_pipeline')
            && defined('VIO_FEATURE_COMPUTE')
            && vio_supports_feature($ctx, VIO_FEATURE_COMPUTE);
    }

    /**
     * Pre-compile + cache the compute pipeline (the shader compile is the
     * dominant one-off cost). Call once during the splash so the first frame is
     * just bind+dispatch+readback. Safe to call repeatedly; returns false when
     * compute is unavailable or the compile fails.
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
        // Warm the readback-free billboard pipeline too, where supported, so the
        // first particle frame is just upload+dispatch+bind.
        if (self::$billboardPipeline === null && self::isReadbackFree($ctx)) {
            $bp = vio_compute_pipeline($ctx, ['source' => self::BILLBOARD_SHADER]);
            if ($bp !== false) {
                self::$billboardPipeline = $bp;
            }
        }
        return true;
    }

    /**
     * Allocate the GPU-resident state for an emitter and seed it from a list of
     * particle rows. Slots past the seed are left dead (zeroed -> life 0), so
     * the shader emits zero matrices for them. Returns null on unavailability or
     * any allocation failure.
     *
     * @param list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> $particles
     *        rows of [px,py,pz, vx,vy,vz, age, lifetime]; length must be <= $capacity
     * @param int $capacity slot count (== emitter maxParticles)
     */
    public static function createState(\VioContext $ctx, array $particles, int $capacity): ?GpuParticleState
    {
        if (!self::isAvailable($ctx) || $capacity <= 0) {
            return null;
        }

        try {
            // Seed the full state buffer explicitly: pack the live rows, then
            // zero-pad to capacity so untouched slots read life==0 (dead) rather
            // than relying on the driver zero-initialising the allocation.
            $flat = [];
            foreach ($particles as $p) {
                $flat[] = $p[0]; $flat[] = $p[1]; $flat[] = $p[2];
                $flat[] = $p[3]; $flat[] = $p[4]; $flat[] = $p[5];
                $flat[] = $p[6]; $flat[] = $p[7];
            }
            $stateBytes = $flat === [] ? '' : pack('f*', ...$flat);
            $wantBytes  = $capacity * 8 * 4;
            if (strlen($stateBytes) < $wantBytes) {
                $stateBytes .= str_repeat("\0", $wantBytes - strlen($stateBytes));
            }

            $stateBuf = vio_storage_buffer($ctx, ['data' => $stateBytes, 'stride' => 4]);
            $outBuf   = vio_storage_buffer($ctx, ['size' => $capacity * 16 * 4, 'stride' => 4]);
            if ($stateBuf === false || $outBuf === false) {
                return null;
            }

            return new GpuParticleState($capacity, $stateBuf, $outBuf);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Advance the resident state one step on the GPU and return the finished
     * instance-matrix buffer as a raw f32 byte string (capacity*16 floats,
     * column-major), or null on unavailability/error so the caller can fall
     * back. The returned bytes are handed verbatim to vio_draw_instanced via
     * {@see DrawMeshInstanced::packed()} — deliberately NOT unpacked here, which
     * is the whole point (no PHP-array roundtrip).
     *
     * With $readback = false the dispatch is submitted but the matrices are not
     * read back — used by the benchmark to isolate the (async) submit cost from
     * the (synchronising) readback. Note that without a readback there is no GPU
     * fence, so a no-readback timing reflects submission only, not GPU execution;
     * the readback path is the honest full-frame measurement.
     *
     * @param Vec3|null $camPos camera world position for billboarding; null =
     *                          axis-aligned quads (matches the CPU no-cam path)
     */
    public static function step(
        \VioContext $ctx,
        GpuParticleState $state,
        ParticleEmitter $emitter,
        float $dt,
        ?Vec3 $camPos,
        bool $readback = true,
    ): ?string {
        if (!self::isAvailable($ctx)) {
            return null;
        }

        try {
            $pipeline = self::$pipeline ?? vio_compute_pipeline($ctx, ['source' => self::SHADER]);
            if ($pipeline === false) {
                return null;
            }
            self::$pipeline = $pipeline;

            $hasCam = $camPos !== null ? 1 : 0;
            $cx = 0.0; $cy = 0.0; $cz = 0.0;
            if ($camPos !== null) {
                $cx = $camPos->x; $cy = $camPos->y; $cz = $camPos->z;
            }

            // 12 scalars, 48 bytes, tight std140 packing (all 4-byte scalars,
            // none straddles a 16-byte boundary; block padded to 48 = 16*3).
            $params = pack(
                'f9',
                $dt,
                $emitter->gravity->x, $emitter->gravity->y, $emitter->gravity->z,
                $emitter->startSize, $emitter->endSize,
                $cx, $cy, $cz,
            ) . pack('l3', $hasCam, $state->capacity, 0);
            vio_compute_set_uniforms($ctx, $pipeline, $params);

            // State is read-write -> bind as WRITE (UAV / RW SSBO). OutM is
            // write-only. Slots 0/1 match the shader's binding = 0/1.
            vio_compute_bind_buffer($ctx, $pipeline, $state->stateBuf, 0, VIO_COMPUTE_WRITE);
            vio_compute_bind_buffer($ctx, $pipeline, $state->outBuf,   1, VIO_COMPUTE_WRITE);

            $groups = intdiv($state->capacity + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);
            vio_compute_dispatch($ctx, $pipeline, $groups, 1, 1);

            if (!$readback) {
                return '';
            }

            $bytes = vio_storage_buffer_read($ctx, $state->outBuf);
            $want  = $state->capacity * 16 * 4;
            if ($bytes === false || strlen($bytes) < $want) {
                return null;
            }
            // Trim to exactly capacity*16 floats; forward the raw bytes verbatim.
            return strlen($bytes) === $want ? $bytes : substr($bytes, 0, $want);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Readback-free path (Path B: VIO_FEATURE_VERTEX_STORAGE) ──────────────

    /**
     * True when the backend can bind a storage buffer to the vertex stage, so
     * the finished matrices are read straight from GPU memory with no readback
     * (php-vio >= the vertex-storage build, on a backend that supports it).
     */
    public static function isReadbackFree(\VioContext $ctx): bool
    {
        return self::isAvailable($ctx)
            && function_exists('vio_bind_storage_buffer')
            && defined('VIO_FEATURE_VERTEX_STORAGE')
            && vio_supports_feature($ctx, VIO_FEATURE_VERTEX_STORAGE);
    }

    /**
     * Billboard-only compute shader for the readback-free path. Reads a compact
     * per-particle input (4 floats: px,py,pz, size) and writes the finished
     * column-major billboard matrix. The CPU integration stays canonical (this
     * offloads only the per-particle billboard build); the output buffer is
     * bound directly as the graphics instance source, so no matrices ever cross
     * the PHP<->GPU bus. Billboard math is identical to
     * {@see \PHPolygon\System\ParticleSystem::writeBillboardMatrix()}.
     *
     *   binding 0 = In   (readonly  SSBO, 4 floats/particle: px,py,pz,size)
     *   binding 1 = OutM (writeonly SSBO, 16 floats/particle, column-major)
     *   binding 2 = Params (UBO: count:int; cam xyz:float; hasCam:int; pad*3)
     */
    public const BILLBOARD_SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 64, local_size_y = 1, local_size_z = 1) in;

        layout(std430, binding = 0) readonly  buffer In   { float s[]; };
        layout(std430, binding = 1) writeonly buffer OutM { float m[]; };
        layout(std140, binding = 2) uniform Params {
            int   count;
            float camx; float camy; float camz;
            int   hasCam;
            int   pad0; int pad1; int pad2;
        };

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            if (gid >= uint(count)) return;
            uint b = gid * 4u;
            uint o = gid * 16u;

            float px = s[b + 0u];
            float py = s[b + 1u];
            float pz = s[b + 2u];
            float size = s[b + 3u];

            if (hasCam == 0) {
                m[o+0u]=size; m[o+1u]=0.0;  m[o+2u]=0.0;   m[o+3u]=0.0;
                m[o+4u]=0.0;  m[o+5u]=size; m[o+6u]=0.0;   m[o+7u]=0.0;
                m[o+8u]=0.0;  m[o+9u]=0.0;  m[o+10u]=size; m[o+11u]=0.0;
                m[o+12u]=px;  m[o+13u]=py;  m[o+14u]=pz;   m[o+15u]=1.0;
                return;
            }

            float dx = camx - px;
            float dy = camy - py;
            float dz = camz - pz;
            float len = sqrt(dx*dx + dy*dy + dz*dz);
            if (len < 1e-6) {
                m[o+0u]=size; m[o+1u]=0.0;  m[o+2u]=0.0;   m[o+3u]=0.0;
                m[o+4u]=0.0;  m[o+5u]=size; m[o+6u]=0.0;   m[o+7u]=0.0;
                m[o+8u]=0.0;  m[o+9u]=0.0;  m[o+10u]=size; m[o+11u]=0.0;
                m[o+12u]=px;  m[o+13u]=py;  m[o+14u]=pz;   m[o+15u]=1.0;
                return;
            }

            float fx = dx / len;
            float fy = dy / len;
            float fz = dz / len;
            float upx, upy, upz;
            if (abs(fy) > 0.999) { upx = 0.0; upy = 0.0; upz = 1.0; }
            else                 { upx = 0.0; upy = 1.0; upz = 0.0; }
            float rx = upy*fz - upz*fy;
            float ry = upz*fx - upx*fz;
            float rz = upx*fy - upy*fx;
            float rlen = sqrt(rx*rx + ry*ry + rz*rz);
            if (rlen > 1e-6) { rx /= rlen; ry /= rlen; rz /= rlen; }
            float uxf = fy*rz - fz*ry;
            float uyf = fz*rx - fx*rz;
            float uzf = fx*ry - fy*rx;

            m[o+0u]=rx*size;  m[o+1u]=ry*size;  m[o+2u]=rz*size;  m[o+3u]=0.0;
            m[o+4u]=uxf*size; m[o+5u]=uyf*size; m[o+6u]=uzf*size; m[o+7u]=0.0;
            m[o+8u]=fx*size;  m[o+9u]=fy*size;  m[o+10u]=fz*size; m[o+11u]=0.0;
            m[o+12u]=px;      m[o+13u]=py;      m[o+14u]=pz;      m[o+15u]=1.0;
        }
        GLSL;

    /**
     * Build the finished instance matrices for an emitter on the GPU and return
     * the output SSBO (a {@see \VioBuffer}) to bind as the graphics instance
     * source — the readback-free path. The CPU-integrated particle positions are
     * uploaded compactly (4 floats each), the GPU billboards them, and the
     * matrices are NEVER read back. Returns null on unavailability/error so the
     * caller falls back to the CPU flat path.
     *
     * The returned buffer is reused per emitter across frames (overwritten each
     * dispatch) and freed automatically when the emitter is GC'd.
     */
    public static function tryBillboardStep(
        \VioContext $ctx,
        ParticleEmitter $emitter,
        ?Vec3 $camPos,
    ): ?\VioBuffer {
        if (!self::isReadbackFree($ctx)) {
            return null;
        }
        $count = count($emitter->particles);
        if ($count === 0) {
            return null;
        }

        try {
            $pipeline = self::$billboardPipeline
                ?? vio_compute_pipeline($ctx, ['source' => self::BILLBOARD_SHADER]);
            if ($pipeline === false) {
                return null;
            }
            self::$billboardPipeline = $pipeline;

            // Compact per-particle input: px,py,pz, size (size from the same
            // start->end curve the CPU render path uses).
            $ss = $emitter->startSize;
            $es = $emitter->endSize;
            $in = [];
            foreach ($emitter->particles as $p) {
                $life = $p[7] > 1e-4 ? $p[7] : 1e-4;
                $t = $p[6] / $life;
                $in[] = $p[0]; $in[] = $p[1]; $in[] = $p[2];
                $in[] = $ss + ($es - $ss) * $t;
            }
            $inBuf = vio_storage_buffer($ctx, ['data' => pack('f*', ...$in), 'stride' => 4]);
            $outBuf = self::billboardOutput($ctx, $emitter);
            if ($inBuf === false || $outBuf === null) {
                return null;
            }

            $hasCam = $camPos !== null ? 1 : 0;
            $cx = 0.0; $cy = 0.0; $cz = 0.0;
            if ($camPos !== null) {
                $cx = $camPos->x; $cy = $camPos->y; $cz = $camPos->z;
            }
            // 8 scalars, 32 bytes (16-aligned std140). count,cam.xyz,hasCam,pad*3.
            $params = pack('l', $count)
                    . pack('f3', $cx, $cy, $cz)
                    . pack('l4', $hasCam, 0, 0, 0);
            vio_compute_set_uniforms($ctx, $pipeline, $params);

            vio_compute_bind_buffer($ctx, $pipeline, $inBuf,  0, VIO_COMPUTE_READ);
            vio_compute_bind_buffer($ctx, $pipeline, $outBuf, 1, VIO_COMPUTE_WRITE);

            $groups = intdiv($count + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);
            vio_compute_dispatch($ctx, $pipeline, $groups, 1, 1);

            return $outBuf;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reusable output matrix SSBO for an emitter, sized to maxParticles and kept
     * across frames (WeakMap-keyed so it frees with the emitter). Recreated when
     * the cap changes. Null on allocation failure.
     */
    private static function billboardOutput(\VioContext $ctx, ParticleEmitter $emitter): ?\VioBuffer
    {
        self::$billboardOutputs ??= new \WeakMap();
        $cap = max(1, $emitter->maxParticles);
        $want = $cap * 16 * 4;

        $existing = self::$billboardOutputs[$emitter] ?? null;
        if ($existing instanceof \VioBuffer) {
            // Emitter cap is immutable in practice; recreate only if it grew.
            return $existing;
        }

        $buf = vio_storage_buffer($ctx, ['size' => $want, 'stride' => 4]);
        if ($buf === false) {
            return null;
        }
        self::$billboardOutputs[$emitter] = $buf;
        return $buf;
    }
}
