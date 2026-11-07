<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\InteriorVolume;
use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\System\WindLeavesSystem;

final class WindLeavesSystemTest extends TestCase
{
    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testCalmWindBlowsNoLeaves(): void
    {
        $world = $this->world(0.3);
        (new WindLeavesSystem())->update($world, 0.016);

        self::assertSame(0, iterator_count($world->query(ParticleEmitter::class)));
        self::assertSame(0.0, WindLeavesSystem::rateFor(WindLeavesSystem::START));
        self::assertSame(WindLeavesSystem::MAX_RATE, WindLeavesSystem::rateFor(1.0));
    }

    public function testStrongWindCarriesLeavesAlongIt(): void
    {
        $world = $this->world(0.9);
        (new WindLeavesSystem())->update($world, 0.016);

        $emitters = array_values(iterator_to_array($world->query(ParticleEmitter::class)));
        self::assertCount(1, $emitters);
        $emitter = $emitters[0]->get(ParticleEmitter::class);
        self::assertSame(WindLeavesSystem::MAX_RATE, $emitter->rate);
        self::assertGreaterThan(5.0, $emitter->velocity->x, 'along the wind (+X)');
    }

    public function testNoLeavesInAHall(): void
    {
        $world = $this->world(1.0);
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, 1.0, 0.0)))
            ->attach(new InteriorVolume(new Vec3(5.0, 5.0, 5.0), 0.02));
        (new WindLeavesSystem())->update($world, 0.016);

        self::assertSame(0, iterator_count($world->query(ParticleEmitter::class)));
    }

    private function world(float $intensity): World
    {
        $world = new World();
        $wind = new Wind();
        $wind->intensity = $intensity;
        $wind->direction = new Vec3(1.0, 0.0, 0.0);
        $world->createEntity()->attach($wind);
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, 1.7, 0.0)))
            ->attach(new Camera3DComponent(eyeOffset: new Vec3(0.0, 0.0, 0.0)));

        return $world;
    }
}
