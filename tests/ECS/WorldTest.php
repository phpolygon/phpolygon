<?php

declare(strict_types=1);

namespace PHPolygon\Tests\ECS;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec2;

class WorldTest extends TestCase
{
    public function testCreateEntity(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $this->assertEquals(1, $entity->id);
        $this->assertTrue($entity->isAlive());
        $this->assertEquals(1, $world->entityCount());
    }

    public function testDestroyEntity(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $id = $entity->id;
        $entity->destroy();
        $this->assertFalse($world->isAlive($id));
        $this->assertEquals(0, $world->entityCount());
    }

    public function testEntityIdReuse(): void
    {
        $world = new World();
        $e1 = $world->createEntity();
        $id1 = $e1->id;
        $e1->destroy();

        $e2 = $world->createEntity();
        $this->assertEquals($id1, $e2->id);
    }

    public function testAttachAndGetComponent(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $transform = new Transform2D(position: new Vec2(10, 20));
        $entity->attach($transform);

        $this->assertTrue($entity->has(Transform2D::class));
        $retrieved = $entity->get(Transform2D::class);
        $this->assertSame($transform, $retrieved);
    }

    public function testDetachComponent(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $entity->attach(new Transform2D());
        $entity->detach(Transform2D::class);
        $this->assertFalse($entity->has(Transform2D::class));
    }

    public function testQuerySingleComponent(): void
    {
        $world = new World();
        $e1 = $world->createEntity();
        $e1->attach(new Transform2D());
        $e1->attach(new NameTag('A'));

        $e2 = $world->createEntity();
        $e2->attach(new Transform2D());

        $e3 = $world->createEntity();
        $e3->attach(new NameTag('C'));

        // Query entities with both Transform2D and NameTag
        $query = $world->query(Transform2D::class, NameTag::class);
        $results = $query->toArray();
        $this->assertCount(1, $results);
        $this->assertEquals($e1->id, $results[0]->id);
    }

    public function testQueryMultipleComponents(): void
    {
        $world = new World();

        for ($i = 0; $i < 5; $i++) {
            $e = $world->createEntity();
            $e->attach(new Transform2D());
            if ($i % 2 === 0) {
                $e->attach(new NameTag("Entity {$i}"));
            }
        }

        $withBoth = $world->query(Transform2D::class, NameTag::class)->toArray();
        $this->assertCount(3, $withBoth); // 0, 2, 4

        $withTransform = $world->query(Transform2D::class)->toArray();
        $this->assertCount(5, $withTransform);
    }

    public function testFluentAttach(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $entity
            ->attach(new Transform2D(position: new Vec2(1, 2)))
            ->attach(new NameTag('Test'));

        $this->assertTrue($entity->has(Transform2D::class));
        $this->assertTrue($entity->has(NameTag::class));
    }

    public function testClear(): void
    {
        $world = new World();
        $world->createEntity()->attach(new Transform2D());
        $world->createEntity()->attach(new Transform2D());
        $this->assertEquals(2, $world->entityCount());

        $world->clear();
        $this->assertEquals(0, $world->entityCount());
    }

    public function testDormantEntityExcludedFromIterationButStaysAccessible(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $id = $entity->id;
        $entity->attach(new NameTag('Region'))->attach(new Transform2D());

        $this->assertCount(1, $world->componentPool(NameTag::class));
        $this->assertFalse($world->isDormant($id));

        $world->setEntityDormant($id, true);

        // Excluded from every per-tick iteration surface...
        $this->assertCount(0, $world->componentPool(NameTag::class));
        $this->assertSame([], $world->componentEntities(NameTag::class));
        $this->assertSame(0, $world->componentCount(NameTag::class));
        $this->assertTrue($world->isDormant($id));
        // ...but still alive and individually accessible.
        $this->assertTrue($world->isAlive($id));
        $this->assertInstanceOf(NameTag::class, $world->tryGetComponent($id, NameTag::class));
        $this->assertTrue($world->hasComponent($id, NameTag::class));
        $this->assertSame(1, $world->entityCount());
    }

    public function testWakeRestoresToIteration(): void
    {
        $world = new World();
        $id = $world->createEntity()->attach(new NameTag('R'))->id;
        $world->setEntityDormant($id, true);
        $world->setEntityDormant($id, false);

        $this->assertCount(1, $world->componentPool(NameTag::class));
        $this->assertFalse($world->isDormant($id));
    }

    public function testAttachWhileDormantStaysParked(): void
    {
        $world = new World();
        $id = $world->createEntity()->attach(new NameTag('R'))->id;
        $world->setEntityDormant($id, true);

        $world->attachComponent($id, new Transform2D());

        // A component attached to a dormant entity must NOT leak into the active
        // pool — it stays parked and re-enters iteration only on wake.
        $this->assertCount(0, $world->componentPool(Transform2D::class));
        $this->assertTrue($world->hasComponent($id, Transform2D::class));
        $world->setEntityDormant($id, false);
        $this->assertCount(1, $world->componentPool(Transform2D::class));
    }

    public function testSetEntitiesDormantBulkAndIdempotent(): void
    {
        $world = new World();
        $a = $world->createEntity()->attach(new NameTag('A'))->id;
        $b = $world->createEntity()->attach(new NameTag('B'))->id;

        $world->setEntitiesDormant([$a, $b], true);
        $this->assertCount(0, $world->componentPool(NameTag::class));

        // Dorming again is a no-op; waking restores both.
        $world->setEntitiesDormant([$a, $b], true);
        $world->setEntitiesDormant([$a, $b], false);
        $this->assertCount(2, $world->componentPool(NameTag::class));
    }

    public function testDestroyDormantEntityCleansParkedStore(): void
    {
        $world = new World();
        $id = $world->createEntity()->attach(new NameTag('R'))->attach(new Transform2D())->id;
        $world->setEntityDormant($id, true);

        $world->destroyEntity($id);

        $this->assertFalse($world->isAlive($id));
        $this->assertFalse($world->isDormant($id));
        $this->assertCount(0, $world->componentPool(NameTag::class));
        // No stale parked component leaks onto the next entity that reuses the id.
        $reuse = $world->createEntity();
        $this->assertFalse($world->hasComponent($reuse->id, NameTag::class));
    }

    public function testClearResetsDormancy(): void
    {
        $world = new World();
        $id = $world->createEntity()->attach(new NameTag('R'))->id;
        $world->setEntityDormant($id, true);

        $world->clear();

        $this->assertSame(0, $world->entityCount());
        $this->assertFalse($world->isDormant($id));
    }
}
