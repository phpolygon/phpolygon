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
 * The shape follows {@see \PHPolygon\Fieldtracing\GpuSdfBaker}: inline GLSL
 * compute consts, shaders warmed at splash, data in raw f32 SSBOs via
 * pack('f*',…), dispatch, {@see vio_storage_buffer_read}, everything best-effort
 * with a null/false fallback. A GPU failure must NEVER fail a frame — the
 * caller falls back to the canonical CPU path in
 * {@see \PHPolygon\System\ParticleSystem}.
 *
 * Keep the integration and billboard math numerically identical to
 * {@see \PHPolygon\System\ParticleSystem::integrate()} /
 * {@see \PHPolygon\System\ParticleSystem::writeBillboardMatrix()} so an A/B
 * comparison stays clean. Particles are purely visual, so float divergence
 * between the CPU and GPU paths is acceptable.
 *
 * SCOPE. Seed a GPU-resident state ({@see createState}), spawn into it while it
 * lives ({@see inject}) and step it ({@see step} / {@see stepIndirect}).
 * ext-vio exposes no partial storage-buffer write, so spawning uploads only the
 * new rows and a small kernel ({@see SPAWN_SHADER}) copies them into the ring
 * slots the state's {@see GpuParticleLedger} hands out. {@see ParticleSystem}
 * drives this per emitter where the backend draws indirectly.
 *
 * Slot compaction: {@see stepIndirect()} lets every live thread claim the next
 * output slot with an atomic and counts them into an indirect draw argument
 * buffer, so the draw covers exactly the live particles instead of always
 * maxParticles — and still nothing is read back
 * ({@see \PHPolygon\Rendering\Command\DrawMeshInstanced::fromStorageBuffer()}
 * with $indirectArgs, php-vio >= 2.17 / VIO_FEATURE_INDIRECT_DRAW).
 *
 * BUFFERS AND BINDINGS — two ext-vio properties shape everything below:
 *   - A storage buffer created from 'data' is a read-only upload buffer on
 *     D3D12; binding it for writing removes the device. Everything a kernel
 *     writes is therefore created with 'size' and filled by a kernel.
 *   - vio_compute_bind_buffer() appends to a list on the pipeline object that
 *     is never cleared (at most 8 entries on D3D12 and OpenGL; later binds are
 *     silently dropped). A pipeline shared between buffers, or bound to a fresh
 *     buffer every frame, ends up dispatching on stale — possibly freed —
 *     buffers after a few calls. Every kernel is therefore created for one
 *     fixed set of buffers and bound exactly once ({@see boundKernel()}): per
 *     state, and per emitter for the billboard path. Uploads go into persistent
 *     buffers rewritten with vio_update_buffer() where the backend implements
 *     that ({@see uploadsRewritable()}); otherwise a spawn gets a fresh upload
 *     buffer and a fresh kernel bound to it.
 */
final class GpuParticleBaker
{
    /** Threads per workgroup — must match the shader's local_size_x. */
    private const LOCAL_SIZE = 64;

    /**
     * The context the static state below belongs to. Objects of one context are
     * never handed to another; see {@see useContext()}.
     *
     * @var \WeakReference<\VioContext>|null
     */
    private static ?\WeakReference $cacheContext = null;

    /** Shaders compiled once on the current context ({@see warm()}). */
    private static bool $warmed = false;

    /** Whether vio_update_buffer() rewrites a storage upload buffer on the current context; null = not probed yet. */
    private static ?bool $uploadsRewritable = null;

    /**
     * Readback-free billboard kit per emitter: [input upload buffer, output
     * matrix buffer, kernel bound to both, capacity in particles]. Freed with
     * the emitter.
     *
     * @var \WeakMap<ParticleEmitter, array{0: \VioBuffer, 1: \VioBuffer, 2: \VioComputePipeline, 3: int}>|null
     */
    private static ?\WeakMap $billboards = null;

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

            // Age first, then test — the order of ParticleSystem::integrate(),
            // which drops a particle on the step its age reaches its lifetime.
            // The aged value is written back for dead slots too, so a later,
            // shorter step can never bring a dead slot back to life.
            float life = s[b + 7u];
            float age  = s[b + 6u] + dt;
            s[b + 6u] = age;

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

            s[b + 0u] = px; s[b + 1u] = py; s[b + 2u] = pz;
            s[b + 3u] = vx; s[b + 4u] = vy; s[b + 5u] = vz;

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
     * Compile every particle kernel once (the first compile of a shader is the
     * dominant one-off cost; later kernels from the same source hit the
     * backend's shader caches) and probe {@see uploadsRewritable()}. Call once
     * during the splash. Safe to call repeatedly; returns false when compute is
     * unavailable or the main shader fails to compile.
     */
    public static function warm(\VioContext $ctx): bool
    {
        if (!self::isAvailable($ctx)) {
            return false;
        }
        self::useContext($ctx);
        if (self::$warmed) {
            return true;
        }
        if (vio_compute_pipeline($ctx, ['source' => self::SHADER]) === false) {
            return false;
        }
        $sources = [self::SPAWN_SHADER];
        if (self::isReadbackFree($ctx)) {
            $sources[] = self::BILLBOARD_SHADER;
        }
        if (self::isIndirectDraw($ctx)) {
            $sources[] = self::RESET_ARGS_SHADER;
            $sources[] = self::compactShader();
        }
        foreach ($sources as $source) {
            vio_compute_pipeline($ctx, ['source' => $source]);
        }
        self::uploadsRewritable($ctx);
        self::$warmed = true;
        return true;
    }

    /**
     * Static state belongs to the context that created it. A different context
     * (a test suite opening one per test, a backend switch) starts from scratch.
     */
    private static function useContext(\VioContext $ctx): void
    {
        if (self::$cacheContext !== null && self::$cacheContext->get() === $ctx) {
            return;
        }
        self::$cacheContext = \WeakReference::create($ctx);
        self::$warmed = false;
        self::$uploadsRewritable = null;
        self::$billboards = null;
    }

    /**
     * A compute pipeline with its buffers bound exactly once. Never rebind the
     * result to other buffers: ext-vio keeps every bound buffer for the
     * pipeline's lifetime (see the class comment).
     *
     * @param list<array{0: \VioBuffer, 1: int, 2: int}> $bindings [buffer, binding slot, VIO_COMPUTE_READ|VIO_COMPUTE_WRITE]
     */
    private static function boundKernel(\VioContext $ctx, string $source, array $bindings): ?\VioComputePipeline
    {
        $kernel = vio_compute_pipeline($ctx, ['source' => $source]);
        if ($kernel === false) {
            return null;
        }
        foreach ($bindings as [$buffer, $slot, $access]) {
            vio_compute_bind_buffer($ctx, $kernel, $buffer, $slot, $access);
        }
        return $kernel;
    }

    /**
     * True when vio_update_buffer() rewrites a storage upload buffer that a
     * kernel then reads. Checked once per context with a one-row round trip
     * through {@see SPAWN_SHADER} — some backends implement the update as a
     * no-op for storage buffers.
     */
    private static function uploadsRewritable(\VioContext $ctx): bool
    {
        self::useContext($ctx);
        if (self::$uploadsRewritable !== null) {
            return self::$uploadsRewritable;
        }
        $ok = false;
        try {
            $row = pack('f8', 1.5, 2.5, 3.5, 4.5, 5.5, 6.5, 7.5, 8.5);
            $target = vio_storage_buffer($ctx, ['size' => strlen($row), 'stride' => 4]);
            $upload = vio_storage_buffer($ctx, ['data' => str_repeat("\0", strlen($row)), 'stride' => 4]);
            if ($target !== false && $upload !== false) {
                $kernel = self::boundKernel($ctx, self::SPAWN_SHADER, [
                    [$target, 0, VIO_COMPUTE_WRITE],
                    [$upload, 1, VIO_COMPUTE_READ],
                ]);
                if ($kernel !== null) {
                    vio_update_buffer($upload, $row);
                    vio_compute_set_uniforms($ctx, $kernel, pack('l4', 0, 1, 1, 0));
                    vio_compute_dispatch($ctx, $kernel, 1, 1, 1);
                    $ok = vio_storage_buffer_read($ctx, $target) === $row;
                }
            }
        } catch (\Throwable) {
            $ok = false;
        }
        self::$uploadsRewritable = $ok;
        return $ok;
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
     * @param int $indexCount index count of the mesh the particles are drawn
     *        with; > 0 additionally allocates the indirect draw argument record
     *        for {@see stepIndirect()} when the backend supports it
     * @param int $generation emitter generation the ring belongs to
     *        ({@see GpuParticleLedger::isCurrent()})
     */
    public static function createState(\VioContext $ctx, array $particles, int $capacity, int $indexCount = 0, int $generation = 0): ?GpuParticleState
    {
        if (!self::isAvailable($ctx) || $capacity <= 0) {
            return null;
        }
        if (count($particles) > $capacity) {
            $particles = array_slice($particles, 0, $capacity);
        }
        self::useContext($ctx);

        try {
            // Every slot is filled once through the spawn kernel: the live rows,
            // zero-padded to capacity so untouched slots read life == 0 (dead)
            // instead of whatever the allocation held.
            $flat = [];
            foreach ($particles as $p) {
                $flat[] = $p[0]; $flat[] = $p[1]; $flat[] = $p[2];
                $flat[] = $p[3]; $flat[] = $p[4]; $flat[] = $p[5];
                $flat[] = $p[6]; $flat[] = $p[7];
            }
            $stateBytes = $flat === [] ? '' : pack('f*', ...$flat);
            $wantBytes  = $capacity * GpuParticleLedger::ROW_FLOATS * 4;
            if (strlen($stateBytes) < $wantBytes) {
                $stateBytes .= str_repeat("\0", $wantBytes - strlen($stateBytes));
            }

            $stateBuf = vio_storage_buffer($ctx, ['size' => $wantBytes, 'stride' => 4]);
            $outBuf   = vio_storage_buffer($ctx, ['size' => $capacity * 16 * 4, 'stride' => 4]);
            if ($stateBuf === false || $outBuf === false) {
                return null;
            }

            // One ring's worth of spawn rows, rewritten per spawn.
            $rowsBuf = null;
            if (self::uploadsRewritable($ctx)) {
                $rows = vio_storage_buffer($ctx, ['data' => str_repeat("\0", $wantBytes), 'stride' => 4]);
                $rowsBuf = $rows === false ? null : $rows;
            }

            // Indirect draw record {indexCount, instanceCount, firstIndex,
            // baseVertex, firstInstance}, GPU-writable: the argument reset
            // kernel writes the whole record before every compacting step,
            // which then counts the live slots into it.
            $argsBuf = null;
            if ($indexCount > 0 && self::isIndirectDraw($ctx)) {
                $args = vio_storage_buffer($ctx, ['size' => 5 * 4, 'stride' => 4, 'indirect' => true]);
                $argsBuf = $args === false ? null : $args;
            }

            // The seeded rows occupy slots 0..n-1; the ledger releases them in
            // slot order as they expire and hands out the slots after them.
            $ledger = new GpuParticleLedger($capacity, $generation);
            $ledger->seed($particles);

            $state = new GpuParticleState($capacity, $stateBuf, $outBuf, $argsBuf, max(0, $indexCount), $ledger, $rowsBuf);
            return self::copyRows($ctx, $state, $stateBytes, $capacity, 0) ? $state : null;
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
     * read back — used by the benchmark to isolate the submit cost from the
     * readback.
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
            // State is read-write -> WRITE (UAV / RW SSBO); OutM is write-only.
            $kernel = $state->stepKernel ??= self::boundKernel($ctx, self::SHADER, [
                [$state->stateBuf, 0, VIO_COMPUTE_WRITE],
                [$state->outBuf,   1, VIO_COMPUTE_WRITE],
            ]);
            if ($kernel === null) {
                return null;
            }

            vio_compute_set_uniforms($ctx, $kernel, self::stepParams($state, $emitter, $dt, $camPos));
            $groups = intdiv($state->capacity + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);
            vio_compute_dispatch($ctx, $kernel, $groups, 1, 1);

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

    /**
     * Params UBO of {@see SHADER} / {@see compactShader()}: 12 scalars, 48 bytes,
     * tight std140 packing (all 4-byte scalars, none straddles a 16-byte
     * boundary; block padded to 48 = 16*3).
     */
    private static function stepParams(GpuParticleState $state, ParticleEmitter $emitter, float $dt, ?Vec3 $camPos): string
    {
        $hasCam = $camPos !== null ? 1 : 0;
        $cx = 0.0; $cy = 0.0; $cz = 0.0;
        if ($camPos !== null) {
            $cx = $camPos->x; $cy = $camPos->y; $cz = $camPos->z;
        }
        return pack(
            'f9',
            $dt,
            $emitter->gravity->x, $emitter->gravity->y, $emitter->gravity->z,
            $emitter->startSize, $emitter->endSize,
            $cx, $cy, $cz,
        ) . pack('l3', $hasCam, $state->capacity, 0);
    }

    /**
     * Advance the resident state one step and compact the live slots to the
     * front of $state->outBuf while counting them into $state->argsBuf — the
     * GPU-driven draw: hand both to
     * {@see \PHPolygon\Rendering\Command\DrawMeshInstanced::fromStorageBuffer()}
     * ($indirectArgs = argsBuf) and the renderer issues vio_draw_indirect() with
     * exactly the live count, nothing read back. Two dispatches: a one-thread
     * reset of the argument record, then the compacting kernel. False when the
     * state carries no argument record (created without an index count, or no
     * VIO_FEATURE_INDIRECT_DRAW) or on any GPU error — fall back to {@see step()}.
     */
    public static function stepIndirect(
        \VioContext $ctx,
        GpuParticleState $state,
        ParticleEmitter $emitter,
        float $dt,
        ?Vec3 $camPos,
    ): bool {
        $args = $state->argsBuf;
        if ($args === null || $state->indexCount <= 0 || !self::isIndirectDraw($ctx)) {
            return false;
        }
        try {
            $reset = $state->resetKernel ??= self::boundKernel($ctx, self::RESET_ARGS_SHADER, [
                [$args, 0, VIO_COMPUTE_WRITE],
            ]);
            $compact = $state->compactKernel ??= self::boundKernel($ctx, self::compactShader(), [
                [$state->stateBuf, 0, VIO_COMPUTE_WRITE],
                [$state->outBuf,   1, VIO_COMPUTE_WRITE],
                [$args,            3, VIO_COMPUTE_WRITE],
            ]);
            if ($reset === null || $compact === null) {
                return false;
            }

            vio_compute_set_uniforms($ctx, $reset, pack('V4', $state->indexCount, 0, 0, 0));
            vio_compute_dispatch($ctx, $reset, 1, 1, 1);

            vio_compute_set_uniforms($ctx, $compact, self::stepParams($state, $emitter, $dt, $camPos));
            $groups = intdiv($state->capacity + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);
            vio_compute_dispatch($ctx, $compact, $groups, 1, 1);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Spawning into a live state ───────────────────────────────────────────

    /**
     * Copies freshly spawned rows into the resident state ring: thread i writes
     * row i to slot (head + i) % capacity, so a batch wraps around the end of
     * the ring. Which slots are free is the {@see GpuParticleLedger}'s call.
     *
     *   binding 0 = State  (RW SSBO, 8 floats/slot)
     *   binding 1 = Rows   (readonly SSBO, 8 floats/row)
     *   binding 2 = Params (UBO: head, n, capacity, pad) — 16 bytes, std140
     */
    public const SPAWN_SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 64, local_size_y = 1, local_size_z = 1) in;

        layout(std430, binding = 0) buffer State { float s[]; };
        layout(std430, binding = 1) readonly buffer Rows { float r[]; };
        layout(std140, binding = 2) uniform Params {
            int head;
            int n;
            int capacity;
            int pad;
        };

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            if (gid >= uint(n)) return;
            uint b = ((uint(head) + gid) % uint(capacity)) * 8u;
            uint i = gid * 8u;
            for (uint k = 0u; k < 8u; k++) s[b + k] = r[i + k];
        }
        GLSL;

    /**
     * Write $n spawned rows (raw f32 bytes, 8 floats per row — see
     * {@see GpuParticleLedger::takePending()}) into the state ring at the
     * ledger's head and move the head past them. Only the new rows cross the
     * bus. True on success (and for $n = 0); false when compute is unavailable,
     * the batch is larger than the ring or shorter than $n rows, or on any GPU
     * error.
     */
    public static function inject(\VioContext $ctx, GpuParticleState $state, string $rows, int $n): bool
    {
        if ($n <= 0) {
            return true;
        }
        if ($n > $state->capacity || !self::isAvailable($ctx)) {
            return false;
        }
        if (!self::copyRows($ctx, $state, $rows, $n, $state->ledger->head())) {
            return false;
        }
        $state->ledger->advanceHead($n);
        return true;
    }

    /**
     * Dispatch {@see SPAWN_SHADER}: copy $n rows (raw f32, 8 floats each) into
     * the ring slots starting at $head — through the state's persistent upload
     * buffer and kernel where uploads are rewritable, otherwise through a fresh
     * upload buffer and a kernel bound to it. False on short input or any GPU
     * error.
     */
    private static function copyRows(\VioContext $ctx, GpuParticleState $state, string $rows, int $n, int $head): bool
    {
        $bytes = $n * GpuParticleLedger::ROW_FLOATS * 4;
        if ($n <= 0 || $n > $state->capacity || strlen($rows) < $bytes) {
            return false;
        }
        if (strlen($rows) !== $bytes) {
            $rows = substr($rows, 0, $bytes);
        }
        try {
            $rowsBuf = $state->rowsBuf;
            if ($rowsBuf !== null && self::uploadsRewritable($ctx)) {
                $kernel = $state->spawnKernel ??= self::boundKernel($ctx, self::SPAWN_SHADER, [
                    [$state->stateBuf, 0, VIO_COMPUTE_WRITE],
                    [$rowsBuf,         1, VIO_COMPUTE_READ],
                ]);
                if ($kernel === null) {
                    return false;
                }
                vio_update_buffer($rowsBuf, $rows);
            } else {
                $upload = vio_storage_buffer($ctx, ['data' => $rows, 'stride' => 4]);
                if ($upload === false) {
                    return false;
                }
                $kernel = self::boundKernel($ctx, self::SPAWN_SHADER, [
                    [$state->stateBuf, 0, VIO_COMPUTE_WRITE],
                    [$upload,          1, VIO_COMPUTE_READ],
                ]);
                if ($kernel === null) {
                    return false;
                }
            }

            vio_compute_set_uniforms($ctx, $kernel, pack('l4', $head, $n, $state->capacity, 0));
            vio_compute_dispatch($ctx, $kernel, intdiv($n + self::LOCAL_SIZE - 1, self::LOCAL_SIZE), 1, 1);
            return true;
        } catch (\Throwable) {
            return false;
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
     * True when the finished matrices can also be drawn with a GPU-written
     * instance count (php-vio >= 2.17, VIO_FEATURE_INDIRECT_DRAW on top of the
     * readback-free path) — the prerequisite for {@see stepIndirect()}.
     */
    public static function isIndirectDraw(\VioContext $ctx): bool
    {
        return self::isReadbackFree($ctx)
            && function_exists('vio_draw_indirect')
            && defined('VIO_FEATURE_INDIRECT_DRAW')
            && vio_supports_feature($ctx, VIO_FEATURE_INDIRECT_DRAW);
    }

    /**
     * Writes the whole indirect argument record before a compacting step:
     * indexCount from the params, instanceCount 0 (the compacting kernel counts
     * into it), offsets 0. The record buffer is GPU-writable and never uploaded
     * from the CPU, so the kernel owns every field.
     *
     *   binding 0 = Args   (RW SSBO, 5 uints)
     *   binding 2 = Params (UBO: indexCount, pad*3) — 16 bytes, std140
     */
    public const RESET_ARGS_SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 1, local_size_y = 1, local_size_z = 1) in;
        layout(std430, binding = 0) buffer Args { uint a[]; };
        layout(std140, binding = 2) uniform Params { uint indexCount; uint pad0; uint pad1; uint pad2; };
        void main() { a[0] = indexCount; a[1] = 0u; a[2] = 0u; a[3] = 0u; a[4] = 0u; }
        GLSL;

    /**
     * {@see SHADER} with slot compaction: a live thread claims the next output
     * slot via atomicAdd on the indirect record's instanceCount (binding 3) and
     * writes its matrix there; dead slots write nothing. Derived from SHADER by
     * text so the integration and billboard math stay one source.
     */
    public static function compactShader(): string
    {
        $src = self::SHADER;
        $edits = [
            "layout(std430, binding = 1) writeonly buffer OutM { float m[]; };\n"
                => "layout(std430, binding = 1) writeonly buffer OutM { float m[]; };\nlayout(std430, binding = 3) buffer Args { uint a[]; };\n",
            "    uint o = gid * 16u;\n" => "    uint o;\n",
            "    if (age >= life || life <= 0.0) {\n        for (uint k = 0u; k < 16u; k++) m[o + k] = 0.0;\n        return;\n    }\n"
                => "    if (age >= life || life <= 0.0) return;\n    // Compaction: claim the next live instance slot (indirect draw record).\n    o = atomicAdd(a[1], 1u) * 16u;\n",
        ];
        // The heredoc carries the file's line endings; match them either way.
        $src = str_replace("\r\n", "\n", $src);
        foreach ($edits as $old => $new) {
            if (substr_count($src, $old) !== 1) {
                throw new \LogicException('GpuParticleBaker::SHADER changed; compactShader() anchors need updating');
            }
            $src = str_replace($old, $new, $src);
        }
        return $src;
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
     * uploaded compactly (4 floats each) into the emitter's persistent input
     * buffer, the GPU billboards them, and the matrices are NEVER read back.
     * Returns null on unavailability/error — and where uploads cannot be
     * rewritten in place, since a fresh kernel every frame would cost more than
     * it saves — so the caller falls back to the CPU flat path.
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
        if ($count === 0 || !self::uploadsRewritable($ctx)) {
            return null;
        }

        try {
            $kit = self::billboardKit($ctx, $emitter, $count);
            if ($kit === null) {
                return null;
            }
            [$inBuf, $outBuf, $kernel] = $kit;

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
            vio_update_buffer($inBuf, pack('f*', ...$in));

            $hasCam = $camPos !== null ? 1 : 0;
            $cx = 0.0; $cy = 0.0; $cz = 0.0;
            if ($camPos !== null) {
                $cx = $camPos->x; $cy = $camPos->y; $cz = $camPos->z;
            }
            // 8 scalars, 32 bytes (16-aligned std140). count,cam.xyz,hasCam,pad*3.
            $params = pack('l', $count)
                    . pack('f3', $cx, $cy, $cz)
                    . pack('l4', $hasCam, 0, 0, 0);
            vio_compute_set_uniforms($ctx, $kernel, $params);

            $groups = intdiv($count + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);
            vio_compute_dispatch($ctx, $kernel, $groups, 1, 1);

            return $outBuf;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The emitter's billboard input buffer, output matrix buffer and the kernel
     * bound to both, sized to maxParticles (or the live count, if larger) and
     * recreated when the particles outgrow it. Null on allocation failure.
     *
     * @return array{0: \VioBuffer, 1: \VioBuffer, 2: \VioComputePipeline, 3: int}|null
     */
    private static function billboardKit(\VioContext $ctx, ParticleEmitter $emitter, int $count): ?array
    {
        self::useContext($ctx);
        self::$billboards ??= new \WeakMap();
        $kit = self::$billboards[$emitter] ?? null;
        if ($kit !== null && $kit[3] >= $count) {
            return $kit;
        }

        $cap = max(1, $emitter->maxParticles, $count);
        $inBuf = vio_storage_buffer($ctx, ['data' => str_repeat("\0", $cap * 4 * 4), 'stride' => 4]);
        $outBuf = vio_storage_buffer($ctx, ['size' => $cap * 16 * 4, 'stride' => 4]);
        if ($inBuf === false || $outBuf === false) {
            return null;
        }
        $kernel = self::boundKernel($ctx, self::BILLBOARD_SHADER, [
            [$inBuf,  0, VIO_COMPUTE_READ],
            [$outBuf, 1, VIO_COMPUTE_WRITE],
        ]);
        if ($kernel === null) {
            return null;
        }
        $kit = [$inBuf, $outBuf, $kernel, $cap];
        self::$billboards[$emitter] = $kit;
        return $kit;
    }
}
