<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread\Subsystem;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider2D;
use PHPolygon\Component\RigidBody2D;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec2;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\Subsystem\PhysicsSubsystem;

class PhysicsSubsystemTest extends TestCase
{
    public function testPrepareInputExtractsWorldState(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $world->attachComponent($entity->id, new Transform2D(position: new Vec2(10.0, 20.0)));
        $rb = new RigidBody2D();
        $rb->velocity = new Vec2(5.0, 0.0);
        $rb->mass = 2.0;
        $world->attachComponent($entity->id, $rb);

        $subsystem = new PhysicsSubsystem();
        $input = $subsystem->prepareInput($world, 0.016);

        $this->assertSame(0.016, $input['dt']);
        $this->assertArrayHasKey($entity->id, $input['bodies']);

        $body = $input['bodies'][$entity->id];
        $this->assertEqualsWithDelta(10.0, $body['x'], 0.001);
        $this->assertEqualsWithDelta(20.0, $body['y'], 0.001);
        $this->assertEqualsWithDelta(5.0, $body['vx'], 0.001);
        $this->assertEqualsWithDelta(2.0, $body['mass'], 0.001);
    }

    public function testApplyDeltasWritesBackToWorld(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $world->attachComponent($entity->id, new Transform2D(position: new Vec2(0.0, 0.0)));
        $world->attachComponent($entity->id, new RigidBody2D());

        $subsystem = new PhysicsSubsystem();
        $subsystem->applyDeltas($world, [
            'positions' => [$entity->id => [42.0, 99.0]],
            'velocities' => [$entity->id => [10.0, -5.0]],
            'rotations' => [$entity->id => 1.57],
            'angularVelocities' => [$entity->id => 0.5],
        ]);

        $t = $world->getComponent($entity->id, Transform2D::class);
        $rb = $world->getComponent($entity->id, RigidBody2D::class);

        $this->assertEqualsWithDelta(42.0, $t->position->x, 0.001);
        $this->assertEqualsWithDelta(99.0, $t->position->y, 0.001);
        $this->assertEqualsWithDelta(10.0, $rb->velocity->x, 0.001);
        $this->assertEqualsWithDelta(-5.0, $rb->velocity->y, 0.001);
        $this->assertEqualsWithDelta(1.57, $t->rotation, 0.001);
        $this->assertEqualsWithDelta(0.5, $rb->angularVelocity, 0.001);
    }

    public function testNullSchedulerRoundTrip(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $world->attachComponent($entity->id, new Transform2D(position: new Vec2(0.0, 0.0)));
        $world->attachComponent($entity->id, new RigidBody2D());

        $scheduler = new NullThreadScheduler();
        $scheduler->register('physics', PhysicsSubsystem::class);
        $scheduler->boot();

        // Run one frame
        $scheduler->sendAll($world, 0.016);
        $scheduler->recvAll($world);

        $t = $world->getComponent($entity->id, Transform2D::class);
        // With default gravity (0, 980): vy += 980*0.016 = 15.68, y += 15.68*0.016 = 0.25088
        $this->assertGreaterThan(0.0, $t->position->y, 'Body should have fallen due to gravity');
    }

    public function testMultiFrameSimulation(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $world->attachComponent($entity->id, new Transform2D(position: new Vec2(0.0, 0.0)));
        $world->attachComponent($entity->id, new RigidBody2D());

        $scheduler = new NullThreadScheduler();
        $scheduler->register('physics', PhysicsSubsystem::class);
        $scheduler->boot();

        $prevY = 0.0;
        for ($frame = 0; $frame < 10; $frame++) {
            $scheduler->sendAll($world, 0.016);
            $scheduler->recvAll($world);

            $t = $world->getComponent($entity->id, Transform2D::class);
            $this->assertGreaterThanOrEqual($prevY, $t->position->y, "Frame {$frame}: body must keep falling");
            $prevY = $t->position->y;
        }

        $this->assertGreaterThan(1.0, $prevY, 'After 10 frames body should have fallen significantly');
    }
}
