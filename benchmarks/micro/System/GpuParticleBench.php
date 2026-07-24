<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Math\Vec3;
use PHPolygon\System\GpuParticleBaker;
use PHPolygon\System\GpuParticleState;

/**
 * CPU vs GPU-compute particle simulation, side by side in one run.
 *
 * The CPU path is the canonical per-frame work {@see \PHPolygon\System\ParticleSystem}
 * does: semi-implicit Euler integration plus the camera-facing billboard-matrix
 * build. The GPU path is {@see GpuParticleBaker::step()} — one compute dispatch
 * that does both, optionally followed by the matrix readback.
 *
 * Two comparisons, matching the briefing (A.8):
 *   - Integrate+billboard  vs  GPU dispatch WITHOUT readback  (submit cost only;
 *     the dispatch is async, so this is an optimistic lower bound, not the
 *     honest number — there is no fence primitive to force GPU completion).
 *   - Integrate+billboard  vs  GPU dispatch WITH readback     (the honest
 *     full-frame cost: the readback synchronises on GPU completion, and its
 *     bandwidth is exactly what decides whether the offload wins).
 *
 * Scales: N ∈ {256, 4096, 65536}. The CPU path collapses well before 65k; that
 * is where a GPU win, if any, becomes visible.
 *
 * AVAILABILITY: this bench needs a live vio context with compute. It creates a
 * headless OpenGL context lazily; where that fails (CI, no GPU) every GPU method
 * is a no-op and only the CPU methods produce numbers. It is a DEV-MACHINE
 * bench — do NOT wire it as a CI gate (see benchmarks/README / perf-bench.yml,
 * no path filter is added for it on purpose).
 *
 * Run (dev machine with a GPU):
 *   vendor/bin/phpbench run benchmarks/micro/System/GpuParticleBench.php --report=aggregate
 *
 * Particles are seeded with an effectively infinite lifetime so no slot dies
 * across the measured revolutions — the workload stays a constant N on both
 * paths (the CPU array never shrinks, the GPU never drifts into the cheap
 * dead-slot branch).
 */
final class GpuParticleBench
{
    private const DT = 0.016;

    /** Shared across revs/iterations in one process; created once, lazily. */
    private static ?\VioContext $ctx = null;
    private static bool $ctxTried = false;

    private Vec3 $cameraPos;
    private ParticleEmitter $emitter;

    /** @var array<int, list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}>> keyed by N */
    private array $cpuParticles = [];

    /** @var array<int, GpuParticleState> keyed by N */
    private array $gpuStates = [];

    public function setUp(): void
    {
        $this->cameraPos = new Vec3(0.0, 5.0, 20.0);
        $this->emitter = new ParticleEmitter(
            gravity: new Vec3(0.0, -1.0, 0.0),
            startSize: 0.5,
            endSize: 0.1,
        );

        foreach ([256, 4096, 65536] as $n) {
            $seed = $this->seed($n);
            $this->cpuParticles[$n] = $seed;

            $ctx = self::context();
            if ($ctx !== null) {
                GpuParticleBaker::warm($ctx);
                $state = GpuParticleBaker::createState($ctx, $seed, $n);
                if ($state !== null) {
                    $this->gpuStates[$n] = $state;
                }
            }
        }
    }

    /** Lazily open a headless compute context; null when unavailable. */
    private static function context(): ?\VioContext
    {
        if (self::$ctxTried) {
            return self::$ctx;
        }
        self::$ctxTried = true;
        if (!function_exists('vio_create')) {
            return null;
        }
        $ctx = @vio_create('opengl', [
            'width' => 32, 'height' => 32, 'title' => 'gpu-particle-bench',
            'vsync' => false, 'headless' => true,
        ]);
        if ($ctx === false || !GpuParticleBaker::isAvailable($ctx)) {
            return null;
        }
        self::$ctx = $ctx;
        return $ctx;
    }

    // ── CPU: integrate + billboard, the full per-frame particle cost ─────────

    /** @BeforeMethods("setUp") @Revs(200) @Iterations(5) */
    public function benchCpu_256(): void   { $this->cpuFrame(256); }

    /** @BeforeMethods("setUp") @Revs(50) @Iterations(5) */
    public function benchCpu_4096(): void  { $this->cpuFrame(4096); }

    /** @BeforeMethods("setUp") @Revs(10) @Iterations(5) */
    public function benchCpu_65536(): void { $this->cpuFrame(65536); }

    // ── GPU: dispatch WITH readback (the honest full-frame number) ───────────

    /** @BeforeMethods("setUp") @Revs(200) @Iterations(5) */
    public function benchGpuReadback_256(): void   { $this->gpuFrame(256, true); }

    /** @BeforeMethods("setUp") @Revs(50) @Iterations(5) */
    public function benchGpuReadback_4096(): void  { $this->gpuFrame(4096, true); }

    /** @BeforeMethods("setUp") @Revs(10) @Iterations(5) */
    public function benchGpuReadback_65536(): void { $this->gpuFrame(65536, true); }

    // ── GPU: dispatch WITHOUT readback (submit-only lower bound) ─────────────

    /** @BeforeMethods("setUp") @Revs(200) @Iterations(5) */
    public function benchGpuNoReadback_256(): void   { $this->gpuFrame(256, false); }

    /** @BeforeMethods("setUp") @Revs(50) @Iterations(5) */
    public function benchGpuNoReadback_4096(): void  { $this->gpuFrame(4096, false); }

    /** @BeforeMethods("setUp") @Revs(10) @Iterations(5) */
    public function benchGpuNoReadback_65536(): void { $this->gpuFrame(65536, false); }

    // ── Implementations ──────────────────────────────────────────────────────

    /**
     * One CPU frame: integrate every particle (semi-implicit Euler) then build
     * the flat float[N*16] billboard buffer — the exact two hot loops
     * ParticleSystem runs per emitter per frame.
     */
    private function cpuFrame(int $n): void
    {
        $particles = $this->cpuParticles[$n];
        $dt = self::DT;
        $gx = $this->emitter->gravity->x;
        $gy = $this->emitter->gravity->y;
        $gz = $this->emitter->gravity->z;

        // integrate()
        $alive = [];
        foreach ($particles as $p) {
            $newAge = $p[6] + $dt;
            if ($newAge >= $p[7]) continue;
            $vx = $p[3] + $gx * $dt;
            $vy = $p[4] + $gy * $dt;
            $vz = $p[5] + $gz * $dt;
            $alive[] = [
                $p[0] + $vx * $dt, $p[1] + $vy * $dt, $p[2] + $vz * $dt,
                $vx, $vy, $vz, $newAge, $p[7],
            ];
        }

        // render(): flat billboard buffer
        $count = count($alive);
        if ($count === 0) return;
        $matrices = array_fill(0, $count * 16, 0.0);
        $ss = $this->emitter->startSize;
        $es = $this->emitter->endSize;
        $cx = $this->cameraPos->x; $cy = $this->cameraPos->y; $cz = $this->cameraPos->z;
        $i = 0;
        foreach ($alive as $p) {
            $life = $p[7] > 1e-4 ? $p[7] : 1e-4;
            $t = $p[6] / $life;
            $size = $ss + ($es - $ss) * $t;
            $this->billboard($matrices, $i * 16, $p[0], $p[1], $p[2], $size, $cx, $cy, $cz);
            $i++;
        }
    }

    /** One GPU frame: dispatch (+ optional readback). No-op when GPU unavailable. */
    private function gpuFrame(int $n, bool $readback): void
    {
        $ctx = self::context();
        if ($ctx === null || !isset($this->gpuStates[$n])) {
            return;
        }
        GpuParticleBaker::step($ctx, $this->gpuStates[$n], $this->emitter, self::DT, $this->cameraPos, $readback);
    }

    /** @param array<int, float> $out */
    private function billboard(array &$out, int $base, float $px, float $py, float $pz,
                               float $size, float $cx, float $cy, float $cz): void
    {
        $dx = $cx - $px; $dy = $cy - $py; $dz = $cz - $pz;
        $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        if ($len < 1e-6) {
            $out[$base+0]=$size;$out[$base+5]=$size;$out[$base+10]=$size;
            $out[$base+12]=$px;$out[$base+13]=$py;$out[$base+14]=$pz;$out[$base+15]=1.0;
            return;
        }
        $fx = $dx / $len; $fy = $dy / $len; $fz = $dz / $len;
        if (abs($fy) > 0.999) { $upx=0.0;$upy=0.0;$upz=1.0; } else { $upx=0.0;$upy=1.0;$upz=0.0; }
        $rx = $upy*$fz - $upz*$fy; $ry = $upz*$fx - $upx*$fz; $rz = $upx*$fy - $upy*$fx;
        $rlen = sqrt($rx*$rx + $ry*$ry + $rz*$rz);
        if ($rlen > 1e-6) { $rx/=$rlen; $ry/=$rlen; $rz/=$rlen; }
        $uxf = $fy*$rz - $fz*$ry; $uyf = $fz*$rx - $fx*$rz; $uzf = $fx*$ry - $fy*$rx;
        $out[$base+0]=$rx*$size;  $out[$base+1]=$ry*$size;  $out[$base+2]=$rz*$size;
        $out[$base+4]=$uxf*$size; $out[$base+5]=$uyf*$size; $out[$base+6]=$uzf*$size;
        $out[$base+8]=$fx*$size;  $out[$base+9]=$fy*$size;  $out[$base+10]=$fz*$size;
        $out[$base+12]=$px; $out[$base+13]=$py; $out[$base+14]=$pz; $out[$base+15]=1.0;
    }

    /**
     * Seed N particles with an effectively infinite lifetime so nothing dies
     * across the measured revs (constant workload on both paths).
     *
     * @return list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}>
     */
    private function seed(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                ($i % 17) - 8.0, ($i % 11) + 5.0, ($i % 13) - 6.5,
                (($i * 7919) % 100) / 100.0, 1.5, (($i * 6151) % 100) / 100.0,
                ($i % 50) / 50.0, 1.0e9,
            ];
        }
        return $out;
    }
}
