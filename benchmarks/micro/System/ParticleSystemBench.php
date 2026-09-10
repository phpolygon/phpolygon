<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\GpuParticleLedger;
use PHPolygon\System\ParticleSystem;

/**
 * ParticleSystem per-frame cost through its public entry points.
 *
 *   - frame:  the CPU path at steady state — spawn + integrate (update) and
 *     the flat billboard buffer (render) for one saturated emitter. This is
 *     what every emitter costs where the GPU path is unavailable.
 *   - ledger: the PHP work the GPU path keeps per frame — advance the ring
 *     clock, queue the frame's spawn rows, pack them for the inject kernel
 *     and move the head. It scales with spawned rows, not live particles.
 *
 * Scales: 256 (a small emitter) and 4096 (a dense one), lifetime 2 s at 60 Hz,
 * rate chosen so the ring stays saturated.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/ParticleSystemBench.php --report=aggregate
 */
final class ParticleSystemBench
{
    private const DT = 1.0 / 60.0;
    private const LIFETIME = 2.0;
    private const WARMUP_FRAMES = 150;

    /** @var array<int, World> */
    private array $worlds = [];
    /** @var array<int, ParticleSystem> */
    private array $systems = [];
    /** @var array<int, RenderCommandList> */
    private array $lists = [];

    /** @var array<int, GpuParticleLedger> */
    private array $ledgers = [];
    /** @var array<int, list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}>> */
    private array $spawnRows = [];

    public function setUp(): void
    {
        mt_srand(1);
        foreach ([256, 4096] as $n) {
            $list = new RenderCommandList();
            $system = new ParticleSystem($list);
            $world = new World();
            $entity = $world->createEntity();
            $entity->attach(new ParticleEmitter(
                rate: $n / self::LIFETIME * 1.1,
                lifetime: self::LIFETIME,
                maxParticles: $n,
            ));
            $entity->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));
            for ($i = 0; $i < self::WARMUP_FRAMES; $i++) {
                $system->update($world, self::DT);
            }
            $this->worlds[$n] = $world;
            $this->systems[$n] = $system;
            $this->lists[$n] = $list;

            // Ledger at the same steady state: one batch per frame.
            $perFrame = (int) ceil($n / self::LIFETIME * self::DT);
            $rows = [];
            for ($i = 0; $i < $perFrame; $i++) {
                $rows[] = [0.0, 0.0, 0.0, 0.1 * $i, 1.5, -0.1 * $i, 0.0, self::LIFETIME];
            }
            $this->spawnRows[$n] = $rows;
            $ledger = new GpuParticleLedger($n);
            for ($i = 0; $i < self::WARMUP_FRAMES; $i++) {
                $this->ledgerFrame($ledger, $rows);
            }
            $this->ledgers[$n] = $ledger;
        }
    }

    /** @BeforeMethods("setUp") @Revs(200) @Iterations(5) */
    public function benchCpuFrame_256(): void { $this->cpuFrame(256); }

    /** @BeforeMethods("setUp") @Revs(20) @Iterations(5) */
    public function benchCpuFrame_4096(): void { $this->cpuFrame(4096); }

    /** @BeforeMethods("setUp") @Revs(1000) @Iterations(5) */
    public function benchGpuLedger_256(): void { $this->ledgerFrame($this->ledgers[256], $this->spawnRows[256]); }

    /** @BeforeMethods("setUp") @Revs(1000) @Iterations(5) */
    public function benchGpuLedger_4096(): void { $this->ledgerFrame($this->ledgers[4096], $this->spawnRows[4096]); }

    private function cpuFrame(int $n): void
    {
        $this->systems[$n]->update($this->worlds[$n], self::DT);
        $this->lists[$n]->clear();
        $this->systems[$n]->render($this->worlds[$n]);
    }

    /**
     * @param list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}> $rows
     */
    private function ledgerFrame(GpuParticleLedger $ledger, array $rows): void
    {
        $ledger->advance(self::DT);
        $ledger->queue($rows, self::LIFETIME);
        $n = $ledger->pendingCount();
        $ledger->takePending(0.0, -1.0, 0.0);
        $ledger->advanceHead($n);
        $ledger->takeDt();
    }
}
