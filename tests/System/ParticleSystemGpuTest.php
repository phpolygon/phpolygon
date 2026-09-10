<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\ParticleSimulation;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\GpuParticleBaker;
use PHPolygon\System\GpuParticleState;
use PHPolygon\System\ParticleSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * ParticleSystem on a live vio context: emitters are simulated on the GPU and
 * drawn indirectly. The GPU-written instance count must equal the ledger's
 * count and the particle count of the CPU simulation for the same seed, and
 * any GPU failure must hand the emitter back to the CPU.
 *
 * Skipped where the backend has no compute + vertex storage + indirect draws.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class ParticleSystemGpuTest extends TestCase
{
    private const MESH = 'gpu_particle_test_quad';
    private const DT = 0.016;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        if (!function_exists('vio_create')) {
            $this->markTestSkipped('vio_create unavailable');
        }
        $ctx = @vio_create('auto', ['width' => 32, 'height' => 32, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!GpuParticleBaker::isIndirectDraw($ctx)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no compute + vertex storage + indirect draws');
        }
        $this->ctx = $ctx;
        MeshRegistry::register(self::MESH, PlaneMesh::generate(1.0, 1.0));
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return array<string, array{int}> */
    public static function capacities(): array
    {
        return [
            'saturated ring (cap below the steady state)' => [16],
            'roomy ring' => [256],
        ];
    }

    #[DataProvider('capacities')]
    public function testIndirectInstanceCountMatchesLedgerAndCpuSimulation(int $capacity): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        $frames = 40;

        mt_srand(1234);
        [$cpuEmitter] = $this->simulate(null, $capacity, $frames);
        $cpuNext = mt_rand();

        mt_srand(1234);
        [$gpuEmitter, $commands] = $this->simulate($ctx, $capacity, $frames);
        $gpuNext = mt_rand();

        self::assertSame($cpuNext, $gpuNext, 'both paths consume the spawn RNG identically');
        self::assertSame([], $gpuEmitter->particles, 'a GPU-simulated emitter keeps no CPU rows');
        self::assertCount(1, $commands);
        $cmd = $commands[0];
        self::assertTrue($cmd->hasIndirectArgs(), 'the draw carries the GPU-written argument record');
        self::assertInstanceOf(\VioBuffer::class, $cmd->indirectArgs);

        $bytes = vio_storage_buffer_read($ctx, $cmd->indirectArgs);
        self::assertIsString($bytes);
        $rec = unpack('V5', $bytes);
        self::assertIsArray($rec);
        $rec = array_values($rec);

        $mesh = MeshRegistry::get(self::MESH);
        self::assertNotNull($mesh);
        self::assertSame(count($mesh->indices), $rec[0]);
        $cpuCount = count($cpuEmitter->particles);
        self::assertGreaterThan(0, $cpuCount);
        self::assertSame($gpuEmitter->count(), $rec[1], 'GPU compaction count == ledger count');
        self::assertSame($cpuCount, $rec[1], 'GPU count == CPU simulation count for the same seed');
        self::assertSame($cpuCount, $cmd->instanceCount);
        self::assertLessThanOrEqual($capacity, $rec[1]);
    }

    public function testCpuOptOutStaysOnTheCpuPath(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        [$emitter, $commands] = $this->simulate($ctx, 64, 5, ParticleSimulation::Cpu);

        self::assertNull($emitter->gpuLedger);
        self::assertNotSame([], $emitter->particles);
        self::assertCount(1, $commands);
        self::assertFalse($commands[0]->hasIndirectArgs());
    }

    public function testGpuFailureHandsTheEmitterBackToTheCpu(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        $list = new RenderCommandList();
        $system = new class ($list, $ctx) extends ParticleSystem {
            public int $attempts = 0;

            protected function renderGpu(\VioContext $ctx, GpuParticleState $state, ParticleEmitter $emitter, ?Vec3 $cameraPos): bool
            {
                $this->attempts++;
                return false;
            }
        };
        [$world, $emitter] = $this->world(64, ParticleSimulation::Auto);

        $system->update($world, self::DT);
        self::assertNotNull($emitter->gpuLedger, 'first update runs on the GPU');
        self::assertSame([], $emitter->particles);

        $list->clear();
        $system->render($world);
        self::assertSame(1, $system->attempts);
        self::assertNull($emitter->gpuLedger, 'the failed GPU state is dropped');

        for ($i = 0; $i < 3; $i++) {
            $system->update($world, self::DT);
            $list->clear();
            $system->render($world);
        }
        self::assertSame(1, $system->attempts, 'the emitter is never retried on the GPU');
        self::assertNotSame([], $emitter->particles);
        self::assertSame(count($emitter->particles), $emitter->count());
        $draws = $list->ofType(DrawMeshInstanced::class);
        self::assertCount(1, $draws);
        self::assertFalse($draws[0]->hasIndirectArgs());
    }

    public function testClearRestartsTheGpuRing(): void
    {
        $ctx = $this->ctx;
        self::assertNotNull($ctx);
        $list = new RenderCommandList();
        $system = new ParticleSystem($list, $ctx);
        [$world, $emitter] = $this->world(64, ParticleSimulation::Auto);

        for ($i = 0; $i < 5; $i++) {
            $system->update($world, self::DT);
            $list->clear();
            $system->render($world);
        }
        $ledger = $emitter->gpuLedger;
        self::assertNotNull($ledger);
        self::assertGreaterThan(0, $emitter->count());

        $emitter->clear();
        self::assertSame(0, $emitter->count());
        $list->clear();
        $system->render($world);
        self::assertSame([], $list->ofType(DrawMeshInstanced::class), 'a cleared emitter draws nothing until it spawns again');

        $system->update($world, self::DT);
        self::assertNotNull($emitter->gpuLedger);
        self::assertNotSame($ledger, $emitter->gpuLedger, 'a new ring for the new generation');
        self::assertSame(1, $emitter->gpuLedger->generation);
        self::assertSame(3, $emitter->count(), 'only this frame\'s spawns are live');
    }

    /**
     * @return array{0: ParticleEmitter, 1: list<DrawMeshInstanced>}
     */
    private function simulate(?\VioContext $ctx, int $capacity, int $frames, ParticleSimulation $simulation = ParticleSimulation::Auto): array
    {
        $list = new RenderCommandList();
        $system = new ParticleSystem($list, $ctx);
        [$world, $emitter] = $this->world($capacity, $simulation);
        for ($i = 0; $i < $frames; $i++) {
            $system->update($world, self::DT);
            $list->clear();
            $system->render($world);
        }
        return [$emitter, $list->ofType(DrawMeshInstanced::class)];
    }

    /**
     * rate 200/s at 16 ms = 3.2 spawns per frame; lifetime 0.1 s = 6 frames
     * (0.096 s alive, dead at 0.112 s — no expiry sits near a frame boundary).
     *
     * @return array{0: World, 1: ParticleEmitter}
     */
    private function world(int $capacity, ParticleSimulation $simulation): array
    {
        $world = new World();
        $emitter = new ParticleEmitter(
            meshId: self::MESH,
            materialId: 'gpu_particle_test_mat',
            rate: 200.0,
            lifetime: 0.1,
            velocity: new Vec3(0.0, 1.0, 0.0),
            velocityJitter: new Vec3(0.5, 0.2, 0.5),
            gravity: new Vec3(0.0, -1.0, 0.0),
            maxParticles: $capacity,
            simulation: $simulation,
        );
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));
        return [$world, $emitter];
    }
}
