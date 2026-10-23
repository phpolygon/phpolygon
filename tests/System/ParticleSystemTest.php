<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\ParticleRenderMode;
use PHPolygon\Component\ParticleSimulation;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\ParticleOrientation;
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

    /**
     * A CPU emitter whose whole reach lies behind the camera skips its draw
     * once it has stood still for a particle lifetime - unless its particles
     * are opaque enough to cast a shadow, or it is in view.
     *
     * @return list<DrawMeshInstanced>
     */
    private function drawsAfterStandingStill(Vec3 $at, string $material, float $still, ?Vec3 $moveTo = null): array
    {
        MaterialRegistry::register('pt_glass', new Material(albedo: new Color(1.0, 1.0, 1.0), alpha: 0.4));
        MaterialRegistry::register('pt_solid', new Material(albedo: new Color(1.0, 1.0, 1.0)));
        $world = new World();
        $cl = new RenderCommandList();
        $sys = new ParticleSystem($cl);
        $emitter = new ParticleEmitter(
            materialId: $material,
            rate: 20.0,
            lifetime: 1.0,
            velocity: new Vec3(0, 1, 0),
            velocityJitter: new Vec3(0.2, 0.2, 0.2),
            gravity: new Vec3(0, -9.81, 0),
        );
        $transform = new Transform3D(position: $at);
        $world->createEntity()->attach($emitter)->attach($transform);

        $camera = new SetCamera(
            viewMatrix: Mat4::lookAt(new Vec3(0, 2, 0), new Vec3(0, 2, -10), new Vec3(0, 1, 0)),
            projectionMatrix: Mat4::perspective(1.0, 16 / 9, 0.1, 500.0),
        );
        $frame = function () use ($sys, $world, $cl, $camera): void {
            $cl->clear();
            $cl->add($camera);
            $sys->render($world);
        };
        $frame();
        for ($t = 0.0; $t < $still; $t += 0.1) {
            $sys->update($world, 0.1);
            $frame();
        }
        if ($moveTo !== null) {
            $transform->position = $moveTo;
            $transform->worldMatrix = $transform->getLocalMatrix();
            $sys->update($world, 0.1);
            $frame();
        }
        self::assertNotSame([], $emitter->particles, 'the emitter keeps simulating');

        return array_values(array_filter($cl->getCommands(), static fn ($c) => $c instanceof DrawMeshInstanced));
    }

    public function testOffScreenTransparentEmitterSkipsItsDraw(): void
    {
        self::assertSame([], $this->drawsAfterStandingStill(new Vec3(0, 2, 60), 'pt_glass', 2.0));
    }

    public function testOffScreenEmitterDrawsUntilItStoodStillForALifetime(): void
    {
        self::assertCount(1, $this->drawsAfterStandingStill(new Vec3(0, 2, 60), 'pt_glass', 0.5));
    }

    public function testOffScreenShadowCastingEmitterKeepsItsDraw(): void
    {
        self::assertCount(1, $this->drawsAfterStandingStill(new Vec3(0, 2, 60), 'pt_solid', 2.0));
    }

    public function testEmitterInViewKeepsItsDraw(): void
    {
        self::assertCount(1, $this->drawsAfterStandingStill(new Vec3(0, 2, -30), 'pt_glass', 2.0));
    }

    public function testMovedEmitterDrawsAgain(): void
    {
        self::assertCount(1, $this->drawsAfterStandingStill(new Vec3(0, 2, 60), 'pt_glass', 2.0, new Vec3(0, 2, 70)));
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

    public function testStretchedParticlesAlignWithTheirVelocityAndTrailBehind(): void
    {
        $cl = new RenderCommandList();
        $cl->add(new SetCamera(
            viewMatrix: Mat4::lookAt(new Vec3(10.0, 0.0, 0.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            projectionMatrix: Mat4::identity(),
        ));
        [$world, $emitter] = $this->emitterWorld(new ParticleEmitter(
            rate: 10.0,
            lifetime: 5.0,
            velocity: new Vec3(0.0, 2.0, 0.0),
            velocityJitter: new Vec3(0.0, 0.0, 0.0),
            gravity: new Vec3(0.0, 0.0, 0.0),
            startSize: 0.2,
            endSize: 0.2,
            renderMode: ParticleRenderMode::Stretched,
            stretch: 0.1,
        ));
        $sys = new ParticleSystem($cl);
        $sys->update($world, 0.1);
        $sys->render($world);

        $m = $cl->ofType(DrawMeshInstanced::class)[0]->flatMatrices;
        $p = $emitter->particles[0];
        // +Y along the velocity, size + speed × stretch long.
        $this->assertEqualsWithDelta([0.0, 0.4, 0.0], [$m[4], $m[5], $m[6]], 1e-9);
        // +X across, +Z towards the camera (+X world), both size long.
        $this->assertEqualsWithDelta([0.0, 0.0, -0.2], [$m[0], $m[1], $m[2]], 1e-9);
        $this->assertEqualsWithDelta([0.2, 0.0, 0.0], [$m[8], $m[9], $m[10]], 1e-9);
        // Centred half the trail behind the particle: its head stays at the particle.
        $this->assertEqualsWithDelta([$p[0], $p[1] - 0.1, $p[2]], [$m[12], $m[13], $m[14]], 1e-9);
    }

    public function testStretchedParticleAtRestOrFacingTheCameraIsABillboard(): void
    {
        $out = array_fill(0, 32, 0.0);
        ParticleOrientation::write($out, 16, ParticleRenderMode::Billboard->code(), 1.0, 2.0, 3.0, 0.0, 0.0, 0.0, 0.3, 0.5, 11.0, 2.0, 3.0, true);
        ParticleOrientation::write($out, 0, ParticleRenderMode::Stretched->code(), 1.0, 2.0, 3.0, 0.0, 0.0, 0.0, 0.3, 0.5, 11.0, 2.0, 3.0, true);
        $this->assertSame(array_slice($out, 16, 16), array_slice($out, 0, 16));

        // Flying straight at the camera: no axis to stretch along either.
        ParticleOrientation::write($out, 0, ParticleRenderMode::Stretched->code(), 1.0, 2.0, 3.0, 4.0, 0.0, 0.0, 0.3, 0.5, 11.0, 2.0, 3.0, true);
        $this->assertSame(array_slice($out, 16, 16), array_slice($out, 0, 16));
    }

    public function testWorldAlignedParticlesKeepWorldAxes(): void
    {
        $cl = new RenderCommandList();
        $cl->add(new SetCamera(
            viewMatrix: Mat4::lookAt(new Vec3(3.0, 5.0, 4.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            projectionMatrix: Mat4::identity(),
        ));
        [$world] = $this->emitterWorld(new ParticleEmitter(
            rate: 10.0,
            lifetime: 5.0,
            startSize: 0.5,
            endSize: 0.5,
            renderMode: ParticleRenderMode::WorldAligned,
        ));
        $sys = new ParticleSystem($cl);
        $sys->update($world, 0.1);
        $sys->render($world);

        $m = $cl->ofType(DrawMeshInstanced::class)[0]->flatMatrices;
        $this->assertSame([0.5, 0.0, 0.0, 0.0, 0.0, 0.5, 0.0, 0.0, 0.0, 0.0, 0.5, 0.0], array_slice($m, 0, 12));
    }

    public function testRadialSpeedAndSpawnRadiusShapeARing(): void
    {
        [$world, $emitter] = $this->emitterWorld(new ParticleEmitter(
            rate: 200.0,
            lifetime: 5.0,
            velocity: new Vec3(0.0, 1.0, 0.0),
            velocityJitter: new Vec3(0.0, 0.0, 0.0),
            gravity: new Vec3(0.0, 0.0, 0.0),
            radialSpeed: 2.0,
            spawnRadius: 1.5,
        ));
        $sys = new ParticleSystem(new RenderCommandList());
        mt_srand(3);
        $sys->update($world, 0.1);

        $this->assertCount(20, $emitter->particles);
        $angles = [];
        foreach ($emitter->particles as $p) {
            $this->assertEqualsWithDelta(1.5, hypot($p[0], $p[2]), 1e-9);
            $this->assertEqualsWithDelta(2.0, hypot($p[3], $p[5]), 1e-9);
            $this->assertSame(1.0, $p[4]);
            // Outward: position and velocity share the direction.
            $this->assertEqualsWithDelta(atan2($p[2], $p[0]), atan2($p[5], $p[3]), 1e-9);
            $angles[] = atan2($p[2], $p[0]);
        }
        [$lo, $hi] = self::bounds($angles);
        $this->assertGreaterThan(M_PI, $hi - $lo, 'directions spread around the axis');
    }

    public function testRadialSpokesSnapTheDirections(): void
    {
        $make = static fn (int $spokes): ParticleEmitter => new ParticleEmitter(
            rate: 400.0,
            lifetime: 5.0,
            velocityJitter: new Vec3(0.0, 0.0, 0.0),
            gravity: new Vec3(0.0, 0.0, 0.0),
            radialSpeed: 1.0,
            maxParticles: 1000,
            radialSpokes: $spokes,
        );
        [$world, $emitter] = $this->emitterWorld($make(4));
        $sys = new ParticleSystem(new RenderCommandList());
        mt_srand(11);
        $sys->update($world, 0.1);

        $seen = [];
        foreach ($emitter->particles as $p) {
            $k = (int) round(atan2($p[5], $p[3]) / (M_PI / 2.0));
            $this->assertEqualsWithDelta($k * M_PI / 2.0, atan2($p[5], $p[3]), 1e-9);
            $seen[($k + 4) % 4] = true;
        }
        $this->assertCount(4, $seen, 'every spoke is used');

        $afterSpokes = mt_rand();

        // Spokes reuse the azimuth draw: as many draws as a free radial emitter.
        [$worldFree] = $this->emitterWorld($make(0));
        mt_srand(11);
        (new ParticleSystem(new RenderCommandList()))->update($worldFree, 0.1);
        $this->assertSame($afterSpokes, mt_rand());
    }

    public function testSpawnRadiusJitterMakesABand(): void
    {
        [$world, $emitter] = $this->emitterWorld(new ParticleEmitter(
            rate: 500.0,
            lifetime: 5.0,
            velocityJitter: new Vec3(0.0, 0.0, 0.0),
            spawnRadius: 2.0,
            spawnRadiusJitter: 0.25,
            maxParticles: 1000,
        ));
        $sys = new ParticleSystem(new RenderCommandList());
        $sys->update($world, 0.1);

        $radii = [];
        foreach ($emitter->particles as $p) {
            $radii[] = hypot($p[0], $p[2]);
        }
        $this->assertCount(50, $radii);
        [$lo, $hi] = self::bounds($radii);
        $this->assertGreaterThanOrEqual(1.75 - 1e-9, $lo);
        $this->assertLessThanOrEqual(2.25 + 1e-9, $hi);
        $this->assertGreaterThan(0.3, $hi - $lo);
    }

    /**
     * @param list<float> $values
     * @return array{0: float, 1: float} smallest and largest value (INF, -INF when empty)
     */
    private static function bounds(array $values): array
    {
        $lo = INF;
        $hi = -INF;
        foreach ($values as $v) {
            $lo = min($lo, $v);
            $hi = max($hi, $v);
        }
        return [$lo, $hi];
    }

    public function testActiveDistanceStopsSpawningWhileTheCameraIsFar(): void
    {
        $cl = new RenderCommandList();
        [$world, $emitter] = $this->emitterWorld(new ParticleEmitter(rate: 100.0, lifetime: 0.25, activeDistance: 20.0));
        $sys = new ParticleSystem($cl);

        // No frame rendered yet: no camera to measure against, the emitter spawns.
        $sys->update($world, 0.1);
        $this->assertCount(10, $emitter->particles);

        $cl->add(new SetCamera(
            viewMatrix: Mat4::lookAt(new Vec3(0.0, 0.0, 50.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            projectionMatrix: Mat4::identity(),
        ));
        $sys->render($world);
        $cl->clear();
        $sys->update($world, 0.1);
        $this->assertCount(10, $emitter->particles, 'far away: the live particles age, nothing new');
        $sys->update($world, 0.2);
        $this->assertCount(0, $emitter->particles, 'and they run out');

        $cl->add(new SetCamera(
            viewMatrix: Mat4::lookAt(new Vec3(0.0, 0.0, 15.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            projectionMatrix: Mat4::identity(),
        ));
        $sys->render($world);
        $sys->update($world, 0.1);
        $this->assertCount(10, $emitter->particles, 'back within range: spawning resumes without a burst');
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
