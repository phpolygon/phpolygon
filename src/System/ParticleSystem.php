<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\ParticleSimulation;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Drives every {@see ParticleEmitter} in the world, on the best of three tiers
 * the context supports:
 *
 *   1. GPU simulation — a vio context (php-vio >=
 *      {@see GpuParticleBaker::MIN_VIO_VERSION}) with compute, vertex storage
 *      and indirect draws, an indexed particle mesh, emitter simulation Auto.
 *      Every emitter dispatches the same kernels, recorded into the frame.
 *      update() only spawns: the rows and the elapsed time go into the
 *      emitter's {@see GpuParticleLedger}. render() writes the new rows into
 *      the GPU slot ring ({@see GpuParticleBaker::inject()}), integrates,
 *      billboards and compacts every slot in one compute pass
 *      ({@see GpuParticleBaker::stepIndirect()}) and emits one indirect draw
 *      whose instance count the GPU wrote. Only freshly spawned rows cross the
 *      bus. Any GPU failure hands the emitter to the CPU for good; its live GPU
 *      particles are dropped.
 *   2. CPU simulation, GPU billboards — vertex storage without indirect draws:
 *      {@see GpuParticleBaker::tryBillboardStep()} builds the matrices.
 *   3. CPU simulation, CPU billboards — the canonical path below.
 *
 * All tiers spawn through {@see spawnRows()}, so they consume `mt_rand()` in
 * the same order and the same seed yields the same particles.
 *
 * CPU path per frame:
 *   1. Integrates position and age. Storage is nested arrays - PHP's
 *      fastest path at this size class according to
 *      benchmarks/micro/System/ParticleStorageBench.php.
 *   2. Spawns new particles up to the emitter rate (with the spawn
 *      accumulator carrying fractional ticks).
 *   3. Builds a flat float[N*16] instance-matrix buffer and emits a
 *      single DrawMeshInstanced::flat() per emitter. The flat buffer
 *      is the source of the measured 4.5x render speed-up - the
 *      Mat4 / Quaternion / Vec3 allocations the previous Mat4-mode
 *      implementation paid per particle were the dominant cost, not
 *      the storage layout.
 *
 * Camera-facing billboard rotation is computed inline (no Quaternion
 * intermediate) and folded straight into the same flat buffer.
 *
 * Per-instance colour interpolation is **not** supported - the renderer
 * would need a parallel colour stream attribute. The per-emitter
 * material colour drives the visible tint.
 */
class ParticleSystem extends AbstractSystem
{
    /** @var \WeakMap<ParticleEmitter, GpuParticleState> GPU simulations by emitter (tier 1) */
    private \WeakMap $gpuStates;

    /** @var \WeakMap<ParticleEmitter, true> emitters whose GPU simulation failed — CPU from then on */
    private \WeakMap $gpuFailed;

    private ?bool $indirectDraw = null;

    /**
     * @param \VioContext|null $ctx The renderer's live vio context. Enables the
     *        GPU tiers where the backend supports them; null (headless, non-vio)
     *        keeps the canonical CPU path.
     * @param bool $gpuSimulation False keeps every emitter's simulation on the
     *        CPU (tiers 2 and 3) even where tier 1 is available.
     */
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly ?\VioContext $ctx = null,
        private readonly bool $gpuSimulation = true,
    ) {
        $this->gpuStates = new \WeakMap();
        $this->gpuFailed = new \WeakMap();
    }

    public function update(World $world, float $dt): void
    {
        if ($dt <= 0.0) return;

        foreach ($world->query(ParticleEmitter::class, Transform3D::class) as $entity) {
            $emitter = $entity->get(ParticleEmitter::class);
            $tx      = $entity->get(Transform3D::class);

            $state = $this->gpuState($emitter);
            if ($state !== null) {
                $ledger = $state->ledger;
                $ledger->advance($dt);
                $rows = $this->spawnRows($emitter, $tx->getWorldPosition(), $dt, $ledger->count());
                if ($rows !== []) {
                    $ledger->queue($rows, $emitter->lifetime);
                }
                continue;
            }

            $this->integrate($emitter, $dt);
            foreach ($this->spawnRows($emitter, $tx->getWorldPosition(), $dt, count($emitter->particles)) as $row) {
                $emitter->particles[] = $row;
            }
        }
    }

    public function render(World $world): void
    {
        $cameraPos = $this->extractCameraPosition();

        foreach ($world->query(ParticleEmitter::class) as $entity) {
            $emitter = $entity->get(ParticleEmitter::class);

            $state = $this->gpuStates[$emitter] ?? null;
            if ($state !== null && $this->ctx !== null) {
                if (!$state->ledger->isCurrent($emitter->maxParticles, $emitter->generation)) {
                    // Cleared or re-capped since the last update: the ring is
                    // stale and the next update replaces it. Nothing to draw.
                    continue;
                }
                if ($this->renderGpu($this->ctx, $state, $emitter, $cameraPos)) {
                    continue;
                }
                $this->abandonGpu($emitter);
            }

            $count = count($emitter->particles);
            if ($count === 0) continue;

            // Readback-free path (Path B): offload the per-particle billboard
            // build to the GPU and bind the result straight as the instance
            // source — no float[N*16] built in PHP, no readback. Falls through
            // to the CPU loop below when unavailable (headless, non-vio, older
            // vio, or any GPU error → tryBillboardStep returns null).
            if ($this->ctx !== null) {
                $outBuf = GpuParticleBaker::tryBillboardStep($this->ctx, $emitter, $cameraPos);
                if ($outBuf !== null) {
                    $this->commandList->add(DrawMeshInstanced::fromStorageBuffer(
                        meshId: $emitter->meshId,
                        materialId: $emitter->materialId,
                        storageBuffer: $outBuf,
                        instanceCount: $count,
                    ));
                    continue;
                }
            }

            // Pre-size the instance buffer once via array_fill so PHPStan
            // can infer the array<int, float> shape and the underlying
            // PHP HashTable gets pre-allocated. Single allocation per
            // emitter per frame, no per-particle Mat4 objects.
            /** @var array<int, float> $matrices */
            $matrices = array_fill(0, $count * 16, 0.0);

            $startSize = $emitter->startSize;
            $endSize   = $emitter->endSize;
            $hasCam = $cameraPos !== null;
            $cx = $hasCam ? $cameraPos->x : 0.0;
            $cy = $hasCam ? $cameraPos->y : 0.0;
            $cz = $hasCam ? $cameraPos->z : 0.0;

            $i = 0;
            foreach ($emitter->particles as $p) {
                $life = $p[7] > 1e-4 ? $p[7] : 1e-4;
                $t = $p[6] / $life;
                $size = $startSize + ($endSize - $startSize) * $t;

                $this->writeBillboardMatrix(
                    $matrices,
                    $i * 16,
                    $p[0], $p[1], $p[2],
                    $size,
                    $cx, $cy, $cz,
                    $hasCam,
                );
                $i++;
            }

            $this->commandList->add(DrawMeshInstanced::flat(
                meshId: $emitter->meshId,
                materialId: $emitter->materialId,
                flatMatrices: $matrices,
                instanceCount: $count,
            ));
        }
    }

    /**
     * Tier 1 render: write the rows spawned since the last render into the GPU
     * ring, advance the simulation over the time elapsed since then and emit
     * the indirect draw. False on any GPU failure — the caller then moves the
     * emitter to the CPU.
     */
    protected function renderGpu(\VioContext $ctx, GpuParticleState $state, ParticleEmitter $emitter, ?Vec3 $cameraPos): bool
    {
        $args = $state->argsBuf;
        if ($args === null) {
            return false;
        }
        $ledger = $state->ledger;

        $pending = $ledger->pendingCount();
        if ($pending > 0) {
            $g = $emitter->gravity;
            if (!GpuParticleBaker::inject($ctx, $state, $ledger->takePending($g->x, $g->y, $g->z), $pending)) {
                return false;
            }
        }

        if ($ledger->count() === 0) {
            // Every slot has been released: all particles are dead and stay
            // dead without stepping. Nothing to draw.
            $ledger->takeDt();
            return true;
        }

        if (!GpuParticleBaker::stepIndirect($ctx, $state, $emitter, $ledger->takeDt(), $cameraPos)) {
            return false;
        }

        $this->commandList->add(DrawMeshInstanced::fromStorageBuffer(
            meshId: $emitter->meshId,
            materialId: $emitter->materialId,
            storageBuffer: $state->outBuf,
            instanceCount: $ledger->count(),
            indirectArgs: $args,
        ));
        return true;
    }

    /**
     * The GPU simulation of an emitter that runs on tier 1 — created, or
     * replaced after clear() / a new cap / a new mesh — or null when the
     * emitter is simulated on the CPU.
     */
    private function gpuState(ParticleEmitter $emitter): ?GpuParticleState
    {
        $state = $this->gpuStates[$emitter] ?? null;
        $ctx = $this->ctx;
        $indexCount = $this->gpuIndexCount($emitter);
        if ($ctx === null || $indexCount === 0) {
            if ($state !== null) {
                // Opted out (or the mesh lost its indices): the live GPU
                // particles are not carried over to the CPU.
                unset($this->gpuStates[$emitter]);
                $emitter->gpuLedger = null;
            }
            return null;
        }
        if ($state !== null
            && $state->indexCount === $indexCount
            && $state->ledger->isCurrent($emitter->maxParticles, $emitter->generation)
        ) {
            return $state;
        }

        // Rows the CPU simulated so far seed the ring (always none when the
        // emitter already ran on the GPU), so moving to the GPU keeps them.
        $created = GpuParticleBaker::createState(
            $ctx,
            array_values($emitter->particles),
            $emitter->maxParticles,
            $indexCount,
            $emitter->generation,
        );
        if ($created === null || $created->argsBuf === null) {
            $this->abandonGpu($emitter);
            return null;
        }
        $this->gpuStates[$emitter] = $created;
        $emitter->particles = [];
        $emitter->gpuLedger = $created->ledger;
        return $created;
    }

    /** Index count of the emitter's mesh when the emitter qualifies for tier 1, else 0. */
    private function gpuIndexCount(ParticleEmitter $emitter): int
    {
        if ($this->ctx === null
            || !$this->gpuSimulation
            || $emitter->simulation !== ParticleSimulation::Auto
            || $emitter->maxParticles <= 0
            || isset($this->gpuFailed[$emitter])
        ) {
            return 0;
        }
        $this->indirectDraw ??= GpuParticleBaker::isIndirectDraw($this->ctx);
        if (!$this->indirectDraw) {
            return 0;
        }
        $mesh = MeshRegistry::get($emitter->meshId);
        return $mesh === null ? 0 : count($mesh->indices);
    }

    private function abandonGpu(ParticleEmitter $emitter): void
    {
        unset($this->gpuStates[$emitter]);
        $this->gpuFailed[$emitter] = true;
        $emitter->gpuLedger = null;
    }

    /**
     * Extract the camera world position from the most recent SetCamera
     * command in this frame's command list. Returns null when no camera
     * has been pushed yet (e.g. headless test runs).
     *
     * Contract: ParticleSystem MUST run after the camera system that
     * publishes SetCamera (typically Camera3DSystem).
     */
    private function extractCameraPosition(): ?Vec3
    {
        $latest = $this->commandList->lastOfType(SetCamera::class);
        if ($latest === null) {
            return null;
        }
        return $latest->viewMatrix->inverse()->getTranslation();
    }

    /**
     * Write a translate * rotate * scale matrix into 16 consecutive
     * slots of $out starting at $base, column-major. Inlined for the
     * hot per-particle render loop - no Mat4 / Quaternion / Vec3
     * allocations.
     */
    /**
     * @param array<int, float> $out flat float[N*16] instance buffer; this
     *                               method writes 16 contiguous floats
     *                               starting at $base.
     */
    private function writeBillboardMatrix(
        array &$out, int $base,
        float $px, float $py, float $pz,
        float $size,
        float $cx, float $cy, float $cz,
        bool $hasCam,
    ): void {
        if (!$hasCam) {
            $out[$base + 0]  = $size; $out[$base + 1]  = 0.0;   $out[$base + 2]  = 0.0;   $out[$base + 3]  = 0.0;
            $out[$base + 4]  = 0.0;   $out[$base + 5]  = $size; $out[$base + 6]  = 0.0;   $out[$base + 7]  = 0.0;
            $out[$base + 8]  = 0.0;   $out[$base + 9]  = 0.0;   $out[$base + 10] = $size; $out[$base + 11] = 0.0;
            $out[$base + 12] = $px;   $out[$base + 13] = $py;   $out[$base + 14] = $pz;   $out[$base + 15] = 1.0;
            return;
        }

        $dx = $cx - $px;
        $dy = $cy - $py;
        $dz = $cz - $pz;
        $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        if ($len < 1e-6) {
            $out[$base + 0]  = $size; $out[$base + 1]  = 0.0;   $out[$base + 2]  = 0.0;   $out[$base + 3]  = 0.0;
            $out[$base + 4]  = 0.0;   $out[$base + 5]  = $size; $out[$base + 6]  = 0.0;   $out[$base + 7]  = 0.0;
            $out[$base + 8]  = 0.0;   $out[$base + 9]  = 0.0;   $out[$base + 10] = $size; $out[$base + 11] = 0.0;
            $out[$base + 12] = $px;   $out[$base + 13] = $py;   $out[$base + 14] = $pz;   $out[$base + 15] = 1.0;
            return;
        }

        $fx = $dx / $len;
        $fy = $dy / $len;
        $fz = $dz / $len;
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

    /**
     * Nested-array integrate. Walks the live particle list and rebuilds
     * it skipping anyone whose age has exceeded their lifetime.
     *
     * Why nested rather than SoA: PHP's nested array path is measurably
     * faster at this size class - parallel float arrays cost 8 hash-
     * table lookups per particle, and stride-8 single arrays add index
     * math without saving any. Confirmed in benchmarks/micro/System.
     */
    private function integrate(ParticleEmitter $emitter, float $dt): void
    {
        $gx = $emitter->gravity->x;
        $gy = $emitter->gravity->y;
        $gz = $emitter->gravity->z;
        $alive = [];
        foreach ($emitter->particles as $p) {
            $newAge = $p[6] + $dt;
            if ($newAge >= $p[7]) continue;
            $vx = $p[3] + $gx * $dt;
            $vy = $p[4] + $gy * $dt;
            $vz = $p[5] + $gz * $dt;
            $alive[] = [
                $p[0] + $vx * $dt, $p[1] + $vy * $dt, $p[2] + $vz * $dt,
                $vx, $vy, $vz,
                $newAge, $p[7],
            ];
        }
        $emitter->particles = $alive;
    }

    /**
     * Advance the spawn accumulator and build this step's new particle rows
     * (age 0), capped so the live count never exceeds maxParticles. Shared by
     * every tier: the `mt_rand()` draws happen here and only here, three per
     * row in x, y, z order, so a seed reproduces the same rows on any tier.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>
     */
    private function spawnRows(ParticleEmitter $emitter, Vec3 $position, float $dt, int $liveCount): array
    {
        $emitter->spawnAccumulator += $emitter->rate * $dt;
        $toSpawn = (int) floor($emitter->spawnAccumulator);
        if ($toSpawn <= 0) return [];
        $emitter->spawnAccumulator -= $toSpawn;

        $room = $emitter->maxParticles - $liveCount;
        $toSpawn = min($toSpawn, max(0, $room));
        if ($toSpawn === 0) return [];

        $jx = $emitter->velocityJitter->x;
        $jy = $emitter->velocityJitter->y;
        $jz = $emitter->velocityJitter->z;
        $vbx = $emitter->velocity->x;
        $vby = $emitter->velocity->y;
        $vbz = $emitter->velocity->z;
        $life = $emitter->lifetime;
        $rngMax = mt_getrandmax();

        $rows = [];
        for ($i = 0; $i < $toSpawn; $i++) {
            $rows[] = [
                $position->x, $position->y, $position->z,
                $vbx + (mt_rand() / $rngMax - 0.5) * 2.0 * $jx,
                $vby + (mt_rand() / $rngMax - 0.5) * 2.0 * $jy,
                $vbz + (mt_rand() / $rngMax - 0.5) * 2.0 * $jz,
                0.0, $life,
            ];
        }
        return $rows;
    }
}
