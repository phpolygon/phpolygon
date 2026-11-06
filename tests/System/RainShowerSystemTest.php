<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\InteriorVolume;
use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\System\RainShowerSystem;

final class RainShowerSystemTest extends TestCase
{
    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testNoEmitterWhileItIsDry(): void
    {
        [$world] = $this->world(0.0);
        (new RainShowerSystem())->update($world, 0.016);

        self::assertSame(0, iterator_count($world->query(ParticleEmitter::class)));
    }

    public function testRainFollowsThePlayerAtItsIntensity(): void
    {
        [$world, $weather] = $this->world(0.5);
        $system = new RainShowerSystem();
        $system->update($world, 0.016);

        $emitters = iterator_to_array($world->query(ParticleEmitter::class));
        self::assertCount(1, $emitters);
        $entity = array_values($emitters)[0];
        self::assertEqualsWithDelta(RainShowerSystem::MAX_RATE * 0.5, $entity->get(ParticleEmitter::class)->rate, 1e-6);
        $p = $entity->get(Transform3D::class)->position;
        self::assertEqualsWithDelta(10.0, $p->x, 1e-6);
        self::assertEqualsWithDelta(2.0 + RainShowerSystem::HEIGHT, $p->y, 1e-6);

        // It stops: no new drops, the emitter stays for the next shower.
        $weather->rainIntensity = 0.0;
        $system->update($world, 0.016);
        self::assertSame(0.0, $entity->get(ParticleEmitter::class)->rate);
    }

    public function testNoRainInAHall(): void
    {
        [$world] = $this->world(1.0);
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(10.0, 3.0, -5.0)))
            ->attach(new InteriorVolume(new Vec3(6.0, 4.0, 6.0), 1.0));
        (new RainShowerSystem())->update($world, 0.016);

        self::assertSame(0, iterator_count($world->query(ParticleEmitter::class)));
        self::assertSame(0.0, RainShowerSystem::rateFor(1.0, 1.0));
        self::assertSame(0.0, RainShowerSystem::rateFor(1.0, 0.02), 'any interior volume is dry');
        self::assertGreaterThan(0.0, RainShowerSystem::rateFor(1.0, 0.0));
    }

    public function testNoRainBelowTheWaterSurface(): void
    {
        [$world] = $this->world(1.0);
        (new RainShowerSystem(waterSurfaceY: 5.0))->update($world, 0.016);

        self::assertSame(0, iterator_count($world->query(ParticleEmitter::class)));
    }

    /** @return array{0: World, 1: Weather} */
    private function world(float $rain): array
    {
        $world = new World();
        $weather = new Weather();
        $weather->rainIntensity = $rain;
        $world->createEntity()->attach($weather);
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(10.0, 2.0, -5.0)))
            ->attach(new Camera3DComponent(eyeOffset: new Vec3(0.0, 0.0, 0.0)));

        return [$world, $weather];
    }
}
