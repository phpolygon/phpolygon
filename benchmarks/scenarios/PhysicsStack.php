<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Scenarios;

use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * 100 character controllers stacked over a box-collider floor. Velocity
 * + gravity + collision resolution fire every frame, exercising
 * Physics3DSystem::step() and the BoxCollider BVH cache. No rendering -
 * isolates physics cost.
 */
final class PhysicsStack implements Scenario
{
    public function name(): string
    {
        return 'physics-stack';
    }

    public function setUp(Engine $engine): void
    {
        $world = $engine->world;
        $world->addSystem(new Physics3DSystem(groundPlaneY: 0.0));

        // Floor collider
        $floor = $world->createEntity();
        $world->attachComponent($floor->id, new Transform3D(
            new Vec3(0.0, -0.5, 0.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        ));
        $world->attachComponent($floor->id, new BoxCollider3D(
            size: new Vec3(50.0, 1.0, 50.0),
            isStatic: true,
        ));

        // 100 characters in a 10x10 grid, 5m up
        for ($x = 0; $x < 10; $x++) {
            for ($z = 0; $z < 10; $z++) {
                $character = $world->createEntity();
                $world->attachComponent($character->id, new Transform3D(
                    new Vec3(($x - 5) * 1.5, 5.0 + ($x + $z) * 0.2, ($z - 5) * 1.5),
                    Quaternion::identity(),
                    new Vec3(1.0, 1.0, 1.0),
                ));
                $world->attachComponent($character->id, new CharacterController3D(
                    height: 1.8,
                    radius: 0.4,
                ));
            }
        }
    }

    public function tickFrame(Engine $engine, int $frame, float $dt): void
    {
    }
}
