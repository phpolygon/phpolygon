<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Side-by-side benchmark of the legacy nested-array particle storage
 * vs. the flat-float Struct-of-Arrays storage that landed in this
 * iteration. Each pair runs the same workload (integrate-then-render)
 * at two scales: a typical small emitter (256 particles) and the rain
 * showcase scale (4096 particles).
 *
 * Goal: prove the refactor with measurements rather than estimates.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System --report=aggregate
 *
 * Compare a single iteration's deltas:
 *   vendor/bin/phpbench run benchmarks/micro/System \
 *     --report=aggregate --tag=baseline
 *   # ... edit code ...
 *   vendor/bin/phpbench run benchmarks/micro/System \
 *     --ref=baseline --report=aggregate
 */
final class ParticleStorageBench
{
    private const COUNT_SMALL = 256;
    private const COUNT_LARGE = 4096;

    /** @var array<int, array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> */
    private array $legacyParticlesSmall = [];
    /** @var array<int, array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> */
    private array $legacyParticlesLarge = [];

    /** @var array<int, float> */
    private array $flatPxSmall = [];
    private array $flatPySmall = [];
    private array $flatPzSmall = [];
    private array $flatVxSmall = [];
    private array $flatVySmall = [];
    private array $flatVzSmall = [];
    private array $flatAgeSmall = [];
    private array $flatLifeSmall = [];

    /** @var array<int, float> */
    private array $flatPxLarge = [];
    private array $flatPyLarge = [];
    private array $flatPzLarge = [];
    private array $flatVxLarge = [];
    private array $flatVyLarge = [];
    private array $flatVzLarge = [];
    private array $flatAgeLarge = [];
    private array $flatLifeLarge = [];

    /** Single-array stride-8 variant. $strideSmall[i*8 + j], j=0..7. */
    private array $strideSmall = [];
    private array $strideLarge = [];

    /** @var Vec3 */
    private Vec3 $cameraPos;

    public function setUp(): void
    {
        $this->cameraPos = new Vec3(0.0, 5.0, 20.0);
        $this->legacyParticlesSmall = $this->seedLegacy(self::COUNT_SMALL);
        $this->legacyParticlesLarge = $this->seedLegacy(self::COUNT_LARGE);
        $this->seedFlat(
            self::COUNT_SMALL,
            $this->flatPxSmall, $this->flatPySmall, $this->flatPzSmall,
            $this->flatVxSmall, $this->flatVySmall, $this->flatVzSmall,
            $this->flatAgeSmall, $this->flatLifeSmall,
        );
        $this->seedFlat(
            self::COUNT_LARGE,
            $this->flatPxLarge, $this->flatPyLarge, $this->flatPzLarge,
            $this->flatVxLarge, $this->flatVyLarge, $this->flatVzLarge,
            $this->flatAgeLarge, $this->flatLifeLarge,
        );
        $this->strideSmall = $this->seedStride(self::COUNT_SMALL);
        $this->strideLarge = $this->seedStride(self::COUNT_LARGE);
    }

    // ── INTEGRATE: 256 particles ─────────────────────────────────────────

    /**
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(5)
     */
    public function benchIntegrateLegacy_256(): void
    {
        $this->integrateLegacy($this->legacyParticlesSmall);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(5)
     */
    public function benchIntegrateFlat_256(): void
    {
        $this->integrateFlat(
            self::COUNT_SMALL,
            $this->flatPxSmall, $this->flatPySmall, $this->flatPzSmall,
            $this->flatVxSmall, $this->flatVySmall, $this->flatVzSmall,
            $this->flatAgeSmall, $this->flatLifeSmall,
        );
    }

    // ── INTEGRATE: 4096 particles (rain scenario) ────────────────────────

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchIntegrateLegacy_4096(): void
    {
        $this->integrateLegacy($this->legacyParticlesLarge);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchIntegrateFlat_4096(): void
    {
        $this->integrateFlat(
            self::COUNT_LARGE,
            $this->flatPxLarge, $this->flatPyLarge, $this->flatPzLarge,
            $this->flatVxLarge, $this->flatVyLarge, $this->flatVzLarge,
            $this->flatAgeLarge, $this->flatLifeLarge,
        );
    }

    // ── Stride-8 single-array variant ────────────────────────────────────

    /**
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(5)
     */
    public function benchIntegrateStride_256(): void
    {
        $this->integrateStride(self::COUNT_SMALL, $this->strideSmall);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchIntegrateStride_4096(): void
    {
        $this->integrateStride(self::COUNT_LARGE, $this->strideLarge);
    }

    // ── RENDER: build instance matrices, 256 ─────────────────────────────

    /**
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(5)
     */
    public function benchRenderMat4Mode_256(): void
    {
        $this->renderMat4Mode($this->legacyParticlesSmall);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(5)
     */
    public function benchRenderFlatMode_256(): void
    {
        $this->renderFlatMode(
            self::COUNT_SMALL,
            $this->flatPxSmall, $this->flatPySmall, $this->flatPzSmall,
            $this->flatAgeSmall, $this->flatLifeSmall,
        );
    }

    // ── RENDER: build instance matrices, 4096 (rain) ─────────────────────

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchRenderMat4Mode_4096(): void
    {
        $this->renderMat4Mode($this->legacyParticlesLarge);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchRenderFlatMode_4096(): void
    {
        $this->renderFlatMode(
            self::COUNT_LARGE,
            $this->flatPxLarge, $this->flatPyLarge, $this->flatPzLarge,
            $this->flatAgeLarge, $this->flatLifeLarge,
        );
    }

    /**
     * Hybrid path: nested storage (legacy integrate) + flat float[16]
     * buffer build (no Mat4 alloc). Best-of-both candidate.
     *
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(5)
     */
    public function benchRenderHybrid_256(): void
    {
        $this->renderHybridFlatBuffer($this->legacyParticlesSmall);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchRenderHybrid_4096(): void
    {
        $this->renderHybridFlatBuffer($this->legacyParticlesLarge);
    }

    // ── Implementations ──────────────────────────────────────────────────

    /**
     * Reproduces the OLD ParticleSystem::integrate() implementation:
     * walks the nested array and rebuilds it via $alive[] = [...].
     *
     * @param array<int, array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> $particles
     */
    private function integrateLegacy(array &$particles): void
    {
        $dt = 0.016;
        $gx = 0.0; $gy = -1.0; $gz = 0.0;
        $alive = [];
        foreach ($particles as $p) {
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
        $particles = $alive;
    }

    /**
     * Reproduces the NEW ParticleSystem::integrate() implementation:
     * in-place mutation across parallel float arrays with read/write
     * cursors.
     *
     * @param array<int, float> $px
     * @param array<int, float> $py
     * @param array<int, float> $pz
     * @param array<int, float> $vx
     * @param array<int, float> $vy
     * @param array<int, float> $vz
     * @param array<int, float> $age
     * @param array<int, float> $life
     */
    private function integrateFlat(
        int $count,
        array &$px, array &$py, array &$pz,
        array &$vx, array &$vy, array &$vz,
        array &$age, array &$life,
    ): void {
        $dt = 0.016;
        $gx = 0.0; $gy = -1.0; $gz = 0.0;
        $write = 0;
        for ($read = 0; $read < $count; $read++) {
            $newAge = $age[$read] + $dt;
            if ($newAge >= $life[$read]) continue;
            $nvx = $vx[$read] + $gx * $dt;
            $nvy = $vy[$read] + $gy * $dt;
            $nvz = $vz[$read] + $gz * $dt;
            $px[$write] = $px[$read] + $nvx * $dt;
            $py[$write] = $py[$read] + $nvy * $dt;
            $pz[$write] = $pz[$read] + $nvz * $dt;
            $vx[$write] = $nvx;
            $vy[$write] = $nvy;
            $vz[$write] = $nvz;
            $age[$write] = $newAge;
            $life[$write] = $life[$read];
            $write++;
        }
    }

    /**
     * Reproduces the OLD ParticleSystem::render(): one Mat4 per particle
     * via Mat4::trs(), collected into an array.
     *
     * @param array<int, array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> $particles
     */
    private function renderMat4Mode(array $particles): void
    {
        $matrices = [];
        $startSize = 0.2;
        $endSize = 0.0;
        foreach ($particles as $p) {
            $life = $p[7] > 1e-4 ? $p[7] : 1e-4;
            $t = $p[6] / $life;
            $size = $startSize + ($endSize - $startSize) * $t;
            $matrices[] = Mat4::trs(
                new Vec3($p[0], $p[1], $p[2]),
                Quaternion::identity(),
                new Vec3($size, $size, $size),
            );
        }
    }

    /**
     * Stride-8 variant: single flat array, particle i at indices i*8..i*8+7.
     * Trades 8 hash-table accesses (parallel arrays) for 8 indexed accesses
     * into the same hash-table - one HashTable instead of eight.
     *
     * @param array<int, float> $data
     */
    private function integrateStride(int $count, array &$data): void
    {
        $dt = 0.016;
        $gx = 0.0; $gy = -1.0; $gz = 0.0;
        $write = 0;
        for ($read = 0; $read < $count; $read++) {
            $rb = $read * 8;
            $newAge = $data[$rb + 6] + $dt;
            if ($newAge >= $data[$rb + 7]) continue;
            $nvx = $data[$rb + 3] + $gx * $dt;
            $nvy = $data[$rb + 4] + $gy * $dt;
            $nvz = $data[$rb + 5] + $gz * $dt;
            $wb = $write * 8;
            $data[$wb + 0] = $data[$rb + 0] + $nvx * $dt;
            $data[$wb + 1] = $data[$rb + 1] + $nvy * $dt;
            $data[$wb + 2] = $data[$rb + 2] + $nvz * $dt;
            $data[$wb + 3] = $nvx;
            $data[$wb + 4] = $nvy;
            $data[$wb + 5] = $nvz;
            $data[$wb + 6] = $newAge;
            $data[$wb + 7] = $data[$rb + 7];
            $write++;
        }
    }

    /**
     * Reproduces the NEW ParticleSystem::render(): one pre-sized
     * float[N*16] buffer, written in place.
     *
     * @param array<int, float> $px
     * @param array<int, float> $py
     * @param array<int, float> $pz
     * @param array<int, float> $age
     * @param array<int, float> $life
     */
    private function renderFlatMode(int $count, array $px, array $py, array $pz, array $age, array $life): void
    {
        $matrices = [];
        $matrices[$count * 16 - 1] = 0.0;
        $startSize = 0.2;
        $endSize = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $lifeI = $life[$i] > 1e-4 ? $life[$i] : 1e-4;
            $t = $age[$i] / $lifeI;
            $size = $startSize + ($endSize - $startSize) * $t;
            $base = $i * 16;
            $matrices[$base + 0]  = $size; $matrices[$base + 1]  = 0.0;   $matrices[$base + 2]  = 0.0;   $matrices[$base + 3]  = 0.0;
            $matrices[$base + 4]  = 0.0;   $matrices[$base + 5]  = $size; $matrices[$base + 6]  = 0.0;   $matrices[$base + 7]  = 0.0;
            $matrices[$base + 8]  = 0.0;   $matrices[$base + 9]  = 0.0;   $matrices[$base + 10] = $size; $matrices[$base + 11] = 0.0;
            $matrices[$base + 12] = $px[$i]; $matrices[$base + 13] = $py[$i]; $matrices[$base + 14] = $pz[$i]; $matrices[$base + 15] = 1.0;
        }
    }

    /**
     * Hybrid: read from nested storage, write to a single flat[N*16]
     * buffer. No Mat4 allocations; no SoA storage overhead.
     *
     * @param array<int, array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> $particles
     */
    private function renderHybridFlatBuffer(array $particles): void
    {
        $count = count($particles);
        $matrices = [];
        $matrices[$count * 16 - 1] = 0.0;
        $startSize = 0.2;
        $endSize = 0.0;
        $i = 0;
        foreach ($particles as $p) {
            $life = $p[7] > 1e-4 ? $p[7] : 1e-4;
            $t = $p[6] / $life;
            $size = $startSize + ($endSize - $startSize) * $t;
            $base = $i * 16;
            $matrices[$base + 0]  = $size; $matrices[$base + 1]  = 0.0;   $matrices[$base + 2]  = 0.0;   $matrices[$base + 3]  = 0.0;
            $matrices[$base + 4]  = 0.0;   $matrices[$base + 5]  = $size; $matrices[$base + 6]  = 0.0;   $matrices[$base + 7]  = 0.0;
            $matrices[$base + 8]  = 0.0;   $matrices[$base + 9]  = 0.0;   $matrices[$base + 10] = $size; $matrices[$base + 11] = 0.0;
            $matrices[$base + 12] = $p[0]; $matrices[$base + 13] = $p[1]; $matrices[$base + 14] = $p[2]; $matrices[$base + 15] = 1.0;
            $i++;
        }
    }

    // ── Seeders (not benched; only setUp) ────────────────────────────────

    /**
     * @return array<int, array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}>
     */
    private function seedLegacy(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            // Scatter with deterministic pseudo-random offsets so the
            // integrate path takes a realistic memory pattern.
            $out[] = [
                ($i % 17) - 8.0,                  // px
                ($i % 11) + 5.0,                  // py
                ($i % 13) - 6.5,                  // pz
                (($i * 7919) % 100) / 100.0,      // vx
                1.5,                              // vy
                (($i * 6151) % 100) / 100.0,      // vz
                ($i % 50) / 50.0 * 1.0,           // age in [0, 1)
                2.0,                              // lifetime
            ];
        }
        return $out;
    }

    /**
     * @return array<int, float>
     */
    private function seedStride(int $count): array
    {
        $out = [];
        $out[$count * 8 - 1] = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $b = $i * 8;
            $out[$b + 0] = ($i % 17) - 8.0;
            $out[$b + 1] = ($i % 11) + 5.0;
            $out[$b + 2] = ($i % 13) - 6.5;
            $out[$b + 3] = (($i * 7919) % 100) / 100.0;
            $out[$b + 4] = 1.5;
            $out[$b + 5] = (($i * 6151) % 100) / 100.0;
            $out[$b + 6] = ($i % 50) / 50.0;
            $out[$b + 7] = 2.0;
        }
        return $out;
    }

    /**
     * @param array<int, float> $px
     * @param array<int, float> $py
     * @param array<int, float> $pz
     * @param array<int, float> $vx
     * @param array<int, float> $vy
     * @param array<int, float> $vz
     * @param array<int, float> $age
     * @param array<int, float> $life
     */
    private function seedFlat(
        int $count,
        array &$px, array &$py, array &$pz,
        array &$vx, array &$vy, array &$vz,
        array &$age, array &$life,
    ): void {
        $px = []; $py = []; $pz = [];
        $vx = []; $vy = []; $vz = [];
        $age = []; $life = [];
        for ($i = 0; $i < $count; $i++) {
            $px[$i] = ($i % 17) - 8.0;
            $py[$i] = ($i % 11) + 5.0;
            $pz[$i] = ($i % 13) - 6.5;
            $vx[$i] = (($i * 7919) % 100) / 100.0;
            $vy[$i] = 1.5;
            $vz[$i] = (($i * 6151) % 100) / 100.0;
            $age[$i] = ($i % 50) / 50.0 * 1.0;
            $life[$i] = 2.0;
        }
    }
}
