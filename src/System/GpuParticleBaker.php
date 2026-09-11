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
 * Spawning uploads only the new rows, and a small kernel ({@see SPAWN_SHADER})
 * copies them into the ring slots the state's {@see GpuParticleLedger} hands
 * out, wrapping around the end of the ring. {@see ParticleSystem} drives this
 * per emitter where the backend draws indirectly.
 *
 * Slot compaction: {@see stepIndirect()} lets every live thread claim the next
 * output slot with an atomic and counts them into an indirect draw argument
 * buffer, so the draw covers exactly the live particles instead of always
 * maxParticles — and still nothing is read back
 * ({@see \PHPolygon\Rendering\Command\DrawMeshInstanced::fromStorageBuffer()}
 * with $indirectArgs, php-vio >= 2.17 / VIO_FEATURE_INDIRECT_DRAW).
 *
 * KERNELS, DISPATCHES, UPLOADS. Needs php-vio >= {@see MIN_VIO_VERSION}; older
 * versions keep the CPU tiers ({@see isAvailable()}).
 *   - One compute pipeline per shader per context, compiled in {@see warm()}
 *     and shared by every state and emitter. Each dispatch binds its own
 *     buffers first; since php-vio 2.24.1 a rebind replaces the slot.
 *   - Dispatches are recorded into the open frame (['async' => true]): draws
 *     later in the frame see the kernel's writes and PHP never waits for the
 *     GPU. Outside vio_begin/vio_end they run synchronously. Since php-vio
 *     2.24.3 a recorded dispatch keeps the params it was staged with, also when
 *     the shared kernel is dispatched again before the frame runs.
 *   - CPU-written buffers (spawn rows, billboard input) rotate through
 *     {@see GpuParticleState::UPLOAD_ROTATION} upload buffers. Reusing one
 *     sooner would change what an already recorded dispatch reads: on D3D12
 *     the upload executes before the frame's command list, on Metal
 *     vio_update_buffer writes shared memory a frame still in flight reads.
 */
final class GpuParticleBaker
{
    /** Threads per workgroup — must match the shader's local_size_x. */
    private const LOCAL_SIZE = 64;

    /**
     * Oldest php-vio the GPU paths run on: 2.24.1 lets a dispatch rebind a
     * shared kernel's slots, 2.24.3 keeps the params of every async dispatch.
     */
    public const MIN_VIO_VERSION = '2.24.3';

    private const KERNEL_STEP = 'step';
    private const KERNEL_SPAWN = 'spawn';
    private const KERNEL_BILLBOARD = 'billboard';
    private const KERNEL_RESET = 'reset';
    private const KERNEL_COMPACT = 'compact';

    /**
     * The context the static state below belongs to. Objects of one context are
     * never handed to another; see {@see useContext()}.
     *
     * @var \WeakReference<\VioContext>|null
     */
    private static ?\WeakReference $cacheContext = null;

    /**
     * Shared kernels of the current context by name; false = failed to compile
     * (not retried).
     *
     * @var array<string, \VioComputePipeline|false>
     */
    private static array $kernels = [];

    /**
     * Readback-free billboard buffers per emitter: [input upload buffers in
     * rotation, output matrix buffer, capacity in particles, next input
     * buffer]. Freed with the emitter.
     *
     * @var \WeakMap<ParticleEmitter, array{0: list<\VioBuffer>, 1: \VioBuffer, 2: int, 3: int}>|null
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

    /**
     * True when the context can run the particle kernels: compute support and
     * php-vio >= {@see MIN_VIO_VERSION}.
     */
    public static function isAvailable(\VioContext $ctx): bool
    {
        return function_exists('vio_compute_pipeline')
            && defined('VIO_FEATURE_COMPUTE')
            && version_compare((string) phpversion('vio'), self::MIN_VIO_VERSION, '>=')
            && vio_supports_feature($ctx, VIO_FEATURE_COMPUTE);
    }

    /**
     * Compile the particle kernels of the context. They are shared by every
     * state and emitter, so this is their whole creation cost. Call once during
     * the splash. Safe to call repeatedly; returns false when compute is
     * unavailable or the main kernel fails to compile.
     */
    public static function warm(\VioContext $ctx): bool
    {
        if (!self::isAvailable($ctx) || self::kernel($ctx, self::KERNEL_STEP) === null) {
            return false;
        }
        self::kernel($ctx, self::KERNEL_SPAWN);
        if (self::isReadbackFree($ctx)) {
            self::kernel($ctx, self::KERNEL_BILLBOARD);
        }
        if (self::isIndirectDraw($ctx)) {
            self::kernel($ctx, self::KERNEL_RESET);
            self::kernel($ctx, self::KERNEL_COMPACT);
        }
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
        self::$kernels = [];
        self::$billboards = null;
    }

    /** The context's shared kernel $name, compiled on first use; null when it does not compile. */
    private static function kernel(\VioContext $ctx, string $name): ?\VioComputePipeline
    {
        self::useContext($ctx);
        if (!array_key_exists($name, self::$kernels)) {
            $source = match ($name) {
                self::KERNEL_STEP => self::SHADER,
                self::KERNEL_SPAWN => self::SPAWN_SHADER,
                self::KERNEL_BILLBOARD => self::BILLBOARD_SHADER,
                self::KERNEL_RESET => self::RESET_ARGS_SHADER,
                self::KERNEL_COMPACT => self::compactShader(),
                default => throw new \LogicException("unknown particle kernel {$name}"),
            };
            self::$kernels[$name] = vio_compute_pipeline($ctx, ['source' => $source]);
        }
        $kernel = self::$kernels[$name];
        return $kernel === false ? null : $kernel;
    }

    /**
     * Bind $bindings to a shared kernel, stage $params and dispatch $groups
     * workgroups — recorded into the open frame, synchronous outside one.
     *
     * @param list<array{0: \VioBuffer, 1: int, 2: int}> $bindings [buffer, binding slot, VIO_COMPUTE_READ|VIO_COMPUTE_WRITE]
     */
    private static function dispatch(\VioContext $ctx, \VioComputePipeline $kernel, array $bindings, string $params, int $groups): void
    {
        foreach ($bindings as [$buffer, $slot, $access]) {
            vio_compute_bind_buffer($ctx, $kernel, $buffer, $slot, $access);
        }
        vio_compute_set_uniforms($ctx, $kernel, $params);
        vio_compute_dispatch($ctx, $kernel, $groups, 1, 1, ['async' => true]);
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

            // One ring's worth of spawn rows per upload buffer, in rotation.
            $rowsBufs = [];
            for ($i = 0; $i < GpuParticleState::UPLOAD_ROTATION; $i++) {
                $rows = vio_storage_buffer($ctx, ['data' => str_repeat("\0", $wantBytes), 'stride' => 4]);
                if ($rows === false) {
                    return null;
                }
                $rowsBufs[] = $rows;
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

            $state = new GpuParticleState($capacity, $stateBuf, $outBuf, $argsBuf, max(0, $indexCount), $ledger, $rowsBufs);
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
            $kernel = self::kernel($ctx, self::KERNEL_STEP);
            if ($kernel === null) {
                return null;
            }

            // State is read-write -> WRITE (UAV / RW SSBO); OutM is write-only.
            self::dispatch($ctx, $kernel, [
                [$state->stateBuf, 0, VIO_COMPUTE_WRITE],
                [$state->outBuf,   1, VIO_COMPUTE_WRITE],
            ], self::stepParams($state, $emitter, $dt, $camPos), intdiv($state->capacity + self::LOCAL_SIZE - 1, self::LOCAL_SIZE));

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
            $reset = self::kernel($ctx, self::KERNEL_RESET);
            $compact = self::kernel($ctx, self::KERNEL_COMPACT);
            if ($reset === null || $compact === null) {
                return false;
            }

            self::dispatch($ctx, $reset, [
                [$args, 0, VIO_COMPUTE_WRITE],
            ], pack('V4', $state->indexCount, 0, 0, 0), 1);

            self::dispatch($ctx, $compact, [
                [$state->stateBuf, 0, VIO_COMPUTE_WRITE],
                [$state->outBuf,   1, VIO_COMPUTE_WRITE],
                [$args,            3, VIO_COMPUTE_WRITE],
            ], self::stepParams($state, $emitter, $dt, $camPos), intdiv($state->capacity + self::LOCAL_SIZE - 1, self::LOCAL_SIZE));
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
     * Dispatch {@see SPAWN_SHADER}: upload $n rows (raw f32, 8 floats each) into
     * the state's next upload buffer and copy them into the ring slots starting
     * at $head. False on short input, a state without upload buffers or any GPU
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
            $kernel = self::kernel($ctx, self::KERNEL_SPAWN);
            $rowsBuf = $state->nextRowsBuffer();
            if ($kernel === null || $rowsBuf === null) {
                return false;
            }
            vio_update_buffer($rowsBuf, $rows);
            self::dispatch($ctx, $kernel, [
                [$state->stateBuf, 0, VIO_COMPUTE_WRITE],
                [$rowsBuf,         1, VIO_COMPUTE_READ],
            ], pack('l4', $head, $n, $state->capacity, 0), intdiv($n + self::LOCAL_SIZE - 1, self::LOCAL_SIZE));
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
     * uploaded compactly (4 floats each) into the emitter's next input buffer,
     * the GPU billboards them, and the matrices are NEVER read back.
     * Returns null on unavailability/error, so the caller falls back to the CPU
     * flat path.
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
            $kernel = self::kernel($ctx, self::KERNEL_BILLBOARD);
            $kit = self::billboardKit($ctx, $emitter, $count);
            if ($kernel === null || $kit === null) {
                return null;
            }
            [$inBufs, $outBuf, $cap, $next] = $kit;
            $inBuf = $inBufs[$next];
            $kits = self::$billboards ??= new \WeakMap();
            $kits[$emitter] = [$inBufs, $outBuf, $cap, ($next + 1) % count($inBufs)];

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

            self::dispatch($ctx, $kernel, [
                [$inBuf,  0, VIO_COMPUTE_READ],
                [$outBuf, 1, VIO_COMPUTE_WRITE],
            ], $params, intdiv($count + self::LOCAL_SIZE - 1, self::LOCAL_SIZE));

            return $outBuf;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The emitter's billboard input buffers (in rotation), output matrix buffer,
     * capacity and next input buffer, sized to maxParticles (or the live count,
     * if larger) and recreated when the particles outgrow them. Null on
     * allocation failure.
     *
     * @return array{0: list<\VioBuffer>, 1: \VioBuffer, 2: int, 3: int}|null
     */
    private static function billboardKit(\VioContext $ctx, ParticleEmitter $emitter, int $count): ?array
    {
        self::useContext($ctx);
        self::$billboards ??= new \WeakMap();
        $kit = self::$billboards[$emitter] ?? null;
        if ($kit !== null && $kit[2] >= $count) {
            return $kit;
        }

        $cap = max(1, $emitter->maxParticles, $count);
        $outBuf = vio_storage_buffer($ctx, ['size' => $cap * 16 * 4, 'stride' => 4]);
        if ($outBuf === false) {
            return null;
        }
        $inBufs = [];
        for ($i = 0; $i < GpuParticleState::UPLOAD_ROTATION; $i++) {
            $inBuf = vio_storage_buffer($ctx, ['data' => str_repeat("\0", $cap * 4 * 4), 'stride' => 4]);
            if ($inBuf === false) {
                return null;
            }
            $inBufs[] = $inBuf;
        }
        $kit = [$inBufs, $outBuf, $cap, 0];
        self::$billboards[$emitter] = $kit;
        return $kit;
    }
}
