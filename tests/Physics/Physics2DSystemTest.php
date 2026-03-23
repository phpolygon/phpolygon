<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider2D;
use PHPolygon\Component\RigidBody2D;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\World;
use PHPolygon\Event\CollisionEnter;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Event\TriggerEnter;
use PHPolygon\Math\Vec2;
use PHPolygon\System\Physics2DSystem;

class Physics2DSystemTest extends TestCase
{
    private World $world;
    private Physics2DSystem $physics;
    private EventDispatcher $events;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->events = new EventDispatcher();
        $this->physics = new Physics2DSystem(
            gravity: new Vec2(0.0, 980.0),
            events: $this->events,
        );
        $this->world->addSystem($this->physics);
    }

    public function testGravityApplied(): void
    {
        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D(position: new Vec2(0, 0)));
        $entity->attach(new RigidBody2D());

        $this->world->update(1.0);

        $transform = $entity->get(Transform2D::class);
        // After 1 second of gravity (980 px/s²), velocity = 980, position = 980
        $this->assertGreaterThan(900.0, $transform->position->y);
    }

    public function testKinematicBodyUnaffectedByGravity(): void
    {
        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D(position: new Vec2(100, 100)));
        $entity->attach(new RigidBody2D(isKinematic: true));

        $this->world->update(1.0);

        $transform = $entity->get(Transform2D::class);
        $this->assertEqualsWithDelta(100.0, $transform->position->x, 0.001);
        $this->assertEqualsWithDelta(100.0, $transform->position->y, 0.001);
    }

    public function testZeroGravity(): void
    {
        $this->physics->setGravity(Vec2::zero());

        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D(position: new Vec2(0, 0)));
        $entity->attach(new RigidBody2D(velocity: new Vec2(100, 0)));

        $this->world->update(1.0);

        $transform = $entity->get(Transform2D::class);
        $this->assertEqualsWithDelta(100.0, $transform->position->x, 0.1);
        $this->assertEqualsWithDelta(0.0, $transform->position->y, 0.1);
    }

    public function testDragSlowsVelocity(): void
    {
        $this->physics->setGravity(Vec2::zero());

        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D());
        $entity->attach(new RigidBody2D(velocity: new Vec2(100, 0), drag: 0.5));

        $this->world->update(1.0);

        $rb = $entity->get(RigidBody2D::class);
        $this->assertLessThan(100.0, abs($rb->velocity->x));
    }

    public function testAddForce(): void
    {
        $this->physics->setGravity(Vec2::zero());

        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D());
        $rb = new RigidBody2D(mass: 2.0);
        $entity->attach($rb);

        $rb->addForce(new Vec2(200, 0)); // F=200, m=2 => a=100

        $this->world->update(1.0);

        $transform = $entity->get(Transform2D::class);
        $this->assertEqualsWithDelta(100.0, $transform->position->x, 1.0);
    }

    public function testAddImpulse(): void
    {
        $this->physics->setGravity(Vec2::zero());

        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D());
        $rb = new RigidBody2D(mass: 2.0);
        $entity->attach($rb);

        $rb->addImpulse(new Vec2(200, 0)); // impulse=200, m=2 => dv=100

        $this->assertEqualsWithDelta(100.0, $rb->velocity->x, 0.001);
    }

    public function testAABBCollisionDetection(): void
    {
        $collisions = [];
        $this->events->listen(CollisionEnter::class, function (CollisionEnter $e) use (&$collisions) {
            $collisions[] = $e->collision;
        });

        $this->physics->setGravity(Vec2::zero());

        $a = $this->world->createEntity();
        $a->attach(new Transform2D(position: new Vec2(0, 0)));
        $a->attach(new BoxCollider2D(size: new Vec2(32, 32)));
        $a->attach(new RigidBody2D(isKinematic: true));

        $b = $this->world->createEntity();
        $b->attach(new Transform2D(position: new Vec2(20, 0))); // Overlapping
        $b->attach(new BoxCollider2D(size: new Vec2(32, 32)));
        $b->attach(new RigidBody2D(isKinematic: true));

        $this->world->update(0.016);

        $this->assertCount(1, $collisions);
        $this->assertNotNull($collisions[0]->normal);
        $this->assertGreaterThan(0, $collisions[0]->penetration);
    }

    public function testCollisionResponse(): void
    {
        $this->physics->setGravity(Vec2::zero());

        $a = $this->world->createEntity();
        $a->attach(new Transform2D(position: new Vec2(0, 0)));
        $a->attach(new BoxCollider2D(size: new Vec2(32, 32)));
        $a->attach(new RigidBody2D(velocity: new Vec2(100, 0)));

        $b = $this->world->createEntity();
        $b->attach(new Transform2D(position: new Vec2(30, 0))); // Overlapping by 2px
        $b->attach(new BoxCollider2D(size: new Vec2(32, 32)));
        $b->attach(new RigidBody2D());

        $this->world->update(0.016);

        $rbB = $b->get(RigidBody2D::class);
        // B should have gained some velocity from the collision
        $this->assertNotEquals(0.0, $rbB->velocity->x);
    }

    public function testTriggerEnterEvent(): void
    {
        $triggers = [];
        $this->events->listen(TriggerEnter::class, function (TriggerEnter $e) use (&$triggers) {
            $triggers[] = $e;
        });

        $this->physics->setGravity(Vec2::zero());

        $a = $this->world->createEntity();
        $a->attach(new Transform2D(position: new Vec2(0, 0)));
        $a->attach(new BoxCollider2D(size: new Vec2(32, 32), isTrigger: true));

        $b = $this->world->createEntity();
        $b->attach(new Transform2D(position: new Vec2(10, 0)));
        $b->attach(new BoxCollider2D(size: new Vec2(32, 32)));

        $this->world->update(0.016);

        $this->assertCount(1, $triggers);
    }

    public function testNoCollisionBetweenDistantObjects(): void
    {
        $collisions = [];
        $this->events->listen(CollisionEnter::class, function (CollisionEnter $e) use (&$collisions) {
            $collisions[] = $e;
        });

        $this->physics->setGravity(Vec2::zero());

        $a = $this->world->createEntity();
        $a->attach(new Transform2D(position: new Vec2(0, 0)));
        $a->attach(new BoxCollider2D(size: new Vec2(32, 32)));

        $b = $this->world->createEntity();
        $b->attach(new Transform2D(position: new Vec2(200, 200)));
        $b->attach(new BoxCollider2D(size: new Vec2(32, 32)));

        $this->world->update(0.016);

        $this->assertCount(0, $collisions);
    }

    public function testRaycast(): void
    {
        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D(position: new Vec2(100, 0)));
        $entity->attach(new BoxCollider2D(size: new Vec2(32, 32)));

        $hit = $this->physics->raycast(
            $this->world,
            new Vec2(0, 0),
            new Vec2(1, 0),
            500.0,
        );

        $this->assertNotNull($hit);
        $this->assertSame($entity->id, $hit->entityId);
        $this->assertEqualsWithDelta(84.0, $hit->distance, 1.0);
    }

    public function testRaycastMiss(): void
    {
        $entity = $this->world->createEntity();
        $entity->attach(new Transform2D(position: new Vec2(100, 100)));
        $entity->attach(new BoxCollider2D(size: new Vec2(32, 32)));

        $hit = $this->physics->raycast(
            $this->world,
            new Vec2(0, 0),
            new Vec2(1, 0), // Ray going right, box is at (100, 100)
            500.0,
        );

        $this->assertNull($hit);
    }
}
