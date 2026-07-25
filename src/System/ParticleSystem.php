<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Drives every {@see ParticleEmitter} in the world.
 *
 * Per frame:
 *   1. Spawns new particles up to the emitter rate (with the spawn
 *      accumulator carrying fractional ticks).
 *   2. Integrates position and age. Storage is nested arrays - PHP's
 *      fastest path at this size class according to
 *      benchmarks/micro/System/ParticleStorageBench.php.
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
    /**
     * @param \VioContext|null $ctx When a live vio context is passed AND the
     *        backend supports readback-free instancing (VIO_FEATURE_VERTEX_STORAGE),
     *        the per-particle billboard-matrix build is offloaded to the GPU and
     *        the matrices are read straight from GPU memory — no PHP<->GPU
     *        roundtrip. Null (headless, non-vio, or an older vio) keeps the
     *        canonical CPU path, so behaviour is unchanged there. The CPU
     *        integration in {@see update()} stays authoritative in every case.
     */
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly ?\VioContext $ctx = null,
    ) {}

    public function update(World $world, float $dt): void
    {
        if ($dt <= 0.0) return;

        foreach ($world->query(ParticleEmitter::class, Transform3D::class) as $entity) {
            $emitter = $entity->get(ParticleEmitter::class);
            $tx      = $entity->get(Transform3D::class);

            $this->integrate($emitter, $dt);
            $this->spawn($emitter, $tx->getWorldPosition(), $dt);
        }
    }

    public function render(World $world): void
    {
        $cameraPos = $this->extractCameraPosition();

        foreach ($world->query(ParticleEmitter::class) as $entity) {
            $emitter = $entity->get(ParticleEmitter::class);
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

    private function spawn(ParticleEmitter $emitter, Vec3 $position, float $dt): void
    {
        $emitter->spawnAccumulator += $emitter->rate * $dt;
        $toSpawn = (int) floor($emitter->spawnAccumulator);
        if ($toSpawn <= 0) return;
        $emitter->spawnAccumulator -= $toSpawn;

        $room = $emitter->maxParticles - count($emitter->particles);
        $toSpawn = min($toSpawn, max(0, $room));
        if ($toSpawn === 0) return;

        $jx = $emitter->velocityJitter->x;
        $jy = $emitter->velocityJitter->y;
        $jz = $emitter->velocityJitter->z;
        $vbx = $emitter->velocity->x;
        $vby = $emitter->velocity->y;
        $vbz = $emitter->velocity->z;
        $life = $emitter->lifetime;
        $rngMax = mt_getrandmax();

        for ($i = 0; $i < $toSpawn; $i++) {
            $emitter->particles[] = [
                $position->x, $position->y, $position->z,
                $vbx + (mt_rand() / $rngMax - 0.5) * 2.0 * $jx,
                $vby + (mt_rand() / $rngMax - 0.5) * 2.0 * $jy,
                $vbz + (mt_rand() / $rngMax - 0.5) * 2.0 * $jz,
                0.0, $life,
            ];
        }
    }
}
