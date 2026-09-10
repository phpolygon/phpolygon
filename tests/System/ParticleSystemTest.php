<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\ParticleSimulation;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\ParticleSystem;
use PHPUnit\Framework\TestCase;

class ParticleSystemTest extends TestCase
{
    public function testSpawnsParticlesAtRate(): void
    {
        $world = new World();
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);

        $emitter = new ParticleEmitter(
            rate: 100.0,
            lifetime: 5.0,
            velocity: new Vec3(0, 0, 0),
            velocityJitter: new Vec3(0, 0, 0),
            gravity: new Vec3(0, 0, 0),
            maxParticles: 1000,
        );
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));

        $sys->update($world, 1.0);
        $this->assertCount(100, $emitter->particles);
    }

    public function testParticlesDieWhenLifetimeExceeded(): void
    {
        $world = new World();
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);

        $emitter = new ParticleEmitter(
            rate: 100.0,
            lifetime: 0.5,
            velocity: new Vec3(0, 0, 0),
            velocityJitter: new Vec3(0, 0, 0),
            gravity: new Vec3(0, 0, 0),
        );
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));

        $sys->update($world, 0.1);
        $this->assertGreaterThan(0, count($emitter->particles));

        $sys->update($world, 1.0);
        foreach ($emitter->particles as $p) {
            $this->assertLessThan($emitter->lifetime, $p[6]);
        }
    }

    public function testRespectsMaxParticleCap(): void
    {
        $world = new World();
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);

        $emitter = new ParticleEmitter(
            rate: 1000.0,
            lifetime: 10.0,
            velocity: new Vec3(0, 0, 0),
            velocityJitter: new Vec3(0, 0, 0),
            gravity: new Vec3(0, 0, 0),
            maxParticles: 25,
        );
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));

        $sys->update($world, 1.0);
        $this->assertLessThanOrEqual(25, count($emitter->particles));
    }

    public function testBillboardOrientsParticlesTowardsCamera(): void
    {
        $world = new World();
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);

        $cl->add(new SetCamera(
            viewMatrix: Mat4::lookAt(new Vec3(10.0, 0.0, 0.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            projectionMatrix: Mat4::identity(),
        ));

        $emitter = new ParticleEmitter(rate: 100.0, lifetime: 5.0);
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));

        $sys->update($world, 0.1);
        $sys->render($world);

        $drawCmds = array_values(array_filter(iterator_to_array($cl->getCommands()), fn ($c) => $c instanceof DrawMeshInstanced));
        $this->assertNotEmpty($drawCmds);
        $cmd = $drawCmds[0];
        $this->assertTrue($cmd->hasFlatMatrices(), 'ParticleSystem must use the flat-matrix path');

        // Forward axis = column 2 of the first instance's column-major matrix.
        $flat = $cmd->flatMatrices;
        $fx = $flat[8]; $fy = $flat[9]; $fz = $flat[10];
        $len = sqrt($fx * $fx + $fy * $fy + $fz * $fz);
        $this->assertGreaterThan(1e-3, $len);
        $this->assertEqualsWithDelta(1.0, $fx / $len, 1e-3);
        $this->assertEqualsWithDelta(0.0, $fy / $len, 1e-3);
        $this->assertEqualsWithDelta(0.0, $fz / $len, 1e-3);
    }

    public function testRenderEmitsFlatBufferWithCorrectInstanceCount(): void
    {
        $world = new World();
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);

        $emitter = new ParticleEmitter(
            meshId: 'p_quad',
            materialId: 'p_mat',
            rate: 10.0,
            lifetime: 5.0,
        );
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));

        $sys->update($world, 1.0);
        $sys->render($world);

        $drawCmds = array_values(array_filter(iterator_to_array($cl->getCommands()), fn ($c) => $c instanceof DrawMeshInstanced));
        $this->assertCount(1, $drawCmds);
        $cmd = $drawCmds[0];
        $this->assertSame('p_quad', $cmd->meshId);
        $this->assertSame('p_mat',  $cmd->materialId);
        $this->assertTrue($cmd->hasFlatMatrices());
        $this->assertSame(count($emitter->particles), $cmd->effectiveInstanceCount());
        $this->assertSame(count($emitter->particles) * 16, count($cmd->flatMatrices));
    }

    public function testClearResetsAllStorage(): void
    {
        $emitter = new ParticleEmitter(rate: 50.0, lifetime: 5.0);
        $world = new World();
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));

        $sys->update($world, 1.0);
        $this->assertGreaterThan(0, count($emitter->particles));

        $emitter->clear();
        $this->assertCount(0, $emitter->particles);
    }

    public function testClearStartsANewGeneration(): void
    {
        $emitter = new ParticleEmitter();
        $this->assertSame(0, $emitter->generation);
        $emitter->clear();
        $emitter->clear();
        $this->assertSame(2, $emitter->generation);
        $this->assertNull($emitter->gpuLedger);
        $this->assertSame(0, $emitter->count());
    }

    public function testWithoutContextEmittersStayOnTheFlatCpuPath(): void
    {
        [$world, $emitter] = $this->emitterWorld(new ParticleEmitter(rate: 20.0, lifetime: 5.0));
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl, null, true);

        $sys->update($world, 0.5);
        $sys->render($world);

        $draws = $cl->ofType(DrawMeshInstanced::class);
        $this->assertCount(1, $draws);
        $this->assertTrue($draws[0]->hasFlatMatrices());
        $this->assertFalse($draws[0]->hasStorageBuffer());
        $this->assertNull($emitter->gpuLedger);
        $this->assertSame(10, $emitter->count());
        $this->assertSame(10, $draws[0]->effectiveInstanceCount());
    }

    public function testCpuSimulationOptOutKeepsTheCpuRows(): void
    {
        $this->assertSame(ParticleSimulation::Auto, (new ParticleEmitter())->simulation);

        [$world, $emitter] = $this->emitterWorld(new ParticleEmitter(rate: 20.0, lifetime: 5.0, simulation: ParticleSimulation::Cpu));
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);

        $sys->update($world, 0.5);
        $sys->render($world);

        $this->assertCount(10, $emitter->particles);
        $this->assertNull($emitter->gpuLedger);
        $draws = $cl->ofType(DrawMeshInstanced::class);
        $this->assertCount(1, $draws);
        $this->assertTrue($draws[0]->hasFlatMatrices());
    }

    public function testSpawnConsumesTheRngInTheCanonicalOrder(): void
    {
        $make = static fn (): ParticleEmitter => new ParticleEmitter(
            rate: 30.0,
            lifetime: 5.0,
            velocity: new Vec3(0.0, 1.5, 0.0),
            velocityJitter: new Vec3(0.5, 0.2, 0.3),
            gravity: new Vec3(0.0, 0.0, 0.0),
            maxParticles: 64,
        );
        [$world, $emitter] = $this->emitterWorld($make());
        $sys = new ParticleSystem(new RenderCommandList());

        mt_srand(99);
        $sys->update($world, 0.1); // 3 spawns, freshly spawned rows are not integrated

        // Three draws per particle, x then y then z — the order a downstream
        // determinism check relies on.
        mt_srand(99);
        $max = mt_getrandmax();
        $expected = [];
        for ($i = 0; $i < 3; $i++) {
            $expected[] = [
                0.0, 0.0, 0.0,
                0.0 + (mt_rand() / $max - 0.5) * 2.0 * 0.5,
                1.5 + (mt_rand() / $max - 0.5) * 2.0 * 0.2,
                0.0 + (mt_rand() / $max - 0.5) * 2.0 * 0.3,
                0.0, 5.0,
            ];
        }
        $this->assertSame($expected, $emitter->particles);

        // Same seed on two independent systems and emitters: the same rows over several frames.
        [$worldA, $emitterA] = $this->emitterWorld($make());
        [$worldB, $emitterB] = $this->emitterWorld($make());
        $sysB = new ParticleSystem(new RenderCommandList());
        mt_srand(7);
        for ($i = 0; $i < 5; $i++) {
            $sys->update($worldA, 0.1);
        }
        mt_srand(7);
        for ($i = 0; $i < 5; $i++) {
            $sysB->update($worldB, 0.1);
        }
        $this->assertCount(15, $emitterA->particles);
        $this->assertSame($emitterA->particles, $emitterB->particles);
    }

    /**
     * @return array{0: World, 1: ParticleEmitter}
     */
    private function emitterWorld(ParticleEmitter $emitter): array
    {
        $world = new World();
        $entity = $world->createEntity();
        $entity->attach($emitter);
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));
        return [$world, $emitter];
    }
}
