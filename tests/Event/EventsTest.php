<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Event;

use PHPolygon\ECS\World;
use PHPolygon\Event\CollisionExit;
use PHPolygon\Event\EntityDestroyed;
use PHPolygon\Event\EntitySpawned;
use PHPolygon\Event\TriggerExit;
use PHPUnit\Framework\TestCase;

class EventsTest extends TestCase
{
    public function testEntitySpawnedStoresEntity(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $event = new EntitySpawned($entity);

        $this->assertSame($entity, $event->entity);
        $this->assertSame($entity->id, $event->entity->id);
    }

    public function testEntityDestroyedStoresEntityId(): void
    {
        $event = new EntityDestroyed(99);
        $this->assertSame(99, $event->entityId);
    }

    public function testCollisionExitStoresBothEntities(): void
    {
        $event = new CollisionExit(3, 7);
        $this->assertSame(3, $event->entityA);
        $this->assertSame(7, $event->entityB);
    }

    public function testTriggerExitStoresBothEntities(): void
    {
        $event = new TriggerExit(11, 22);
        $this->assertSame(11, $event->entityA);
        $this->assertSame(22, $event->entityB);
    }
}
