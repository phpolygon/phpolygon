<?php

declare(strict_types=1);

namespace PHPolygon\Tests\ECS;

use PHPUnit\Framework\TestCase;
use PHPolygon\Activity\ActivityTier;
use PHPolygon\Component\NameTag;
use PHPolygon\ECS\World;

/**
 * World's Phase-4 activity-tier API. The central guarantee under test: a
 * Far entity's components stay in the regular pool - componentPool()/query()
 * must see it exactly as a Visible one does, since an unmodified system
 * must not silently stop processing an entity just because something else
 * tagged it Far (see ActivityTier's and AbstractSystem::tierPolicy()'s
 * docblocks).
 */
final class WorldActivityTierTest extends TestCase
{
    public function testFreshEntityIsVisibleByDefault(): void
    {
        $world = new World();
        $entity = $world->createEntity();

        self::assertSame(ActivityTier::Visible, $world->entityTier($entity->id));
        self::assertFalse($world->isFar($entity->id));
    }

    public function testFarEntityStaysInTheRegularComponentPool(): void
    {
        $world = new World();
        $entity = $world->createEntity()->attach(new NameTag('far-one'));

        $world->setEntityTier($entity->id, ActivityTier::Far);

        self::assertTrue($world->isFar($entity->id));
        self::assertSame(ActivityTier::Far, $world->entityTier($entity->id));
        // The defining guarantee: componentPool()/query() are UNCHANGED.
        self::assertArrayHasKey($entity->id, $world->componentPool(NameTag::class));
        $seen = [];
        foreach ($world->query(NameTag::class) as $e) {
            $seen[] = $e->id;
        }
        self::assertSame([$entity->id], $seen);
    }

    public function testComponentPoolFarReturnsOnlyFarEntities(): void
    {
        $world = new World();
        $far = $world->createEntity()->attach(new NameTag('far'));
        $visible = $world->createEntity()->attach(new NameTag('visible'));
        $world->setEntityTier($far->id, ActivityTier::Far);

        $pool = $world->componentPoolFar(NameTag::class);

        self::assertSame([$far->id], array_keys($pool));
        self::assertArrayNotHasKey($visible->id, $pool);
    }

    public function testComponentPoolFarIsEmptyWithoutAnyFarEntity(): void
    {
        $world = new World();
        $world->createEntity()->attach(new NameTag('visible'));

        self::assertSame([], $world->componentPoolFar(NameTag::class));
    }

    public function testNearCollapsesToVisibleAtTheWorldLevel(): void
    {
        // World has no separate storage for Near - see setEntityTier()'s
        // docblock on why. A caller that needs the distinction (ActivitySystem's
        // hysteresis) must track it itself.
        $world = new World();
        $entity = $world->createEntity();

        $world->setEntityTier($entity->id, ActivityTier::Near);

        self::assertSame(ActivityTier::Visible, $world->entityTier($entity->id));
        self::assertFalse($world->isFar($entity->id));
        self::assertFalse($world->isDormant($entity->id));
    }

    public function testDormantTakesPriorityOverFarAndClearsIt(): void
    {
        $world = new World();
        $entity = $world->createEntity()->attach(new NameTag('x'));
        $world->setEntityTier($entity->id, ActivityTier::Far);

        $world->setEntityTier($entity->id, ActivityTier::Dormant);

        self::assertSame(ActivityTier::Dormant, $world->entityTier($entity->id));
        self::assertTrue($world->isDormant($entity->id));
        self::assertFalse($world->isFar($entity->id), 'Dormant and Far are mutually exclusive');
        self::assertArrayNotHasKey($entity->id, $world->componentPool(NameTag::class), 'Dormant still removes from the regular pool - unchanged since before this phase');
    }

    public function testMovingFromDormantToFarWakesItFirst(): void
    {
        $world = new World();
        $entity = $world->createEntity()->attach(new NameTag('x'));
        $world->setEntityTier($entity->id, ActivityTier::Dormant);

        $world->setEntityTier($entity->id, ActivityTier::Far);

        self::assertFalse($world->isDormant($entity->id));
        self::assertTrue($world->isFar($entity->id));
        self::assertArrayHasKey($entity->id, $world->componentPool(NameTag::class));
    }

    public function testMovingBackToVisibleWakesFromDormantAndClearsFar(): void
    {
        $world = new World();
        $entity = $world->createEntity()->attach(new NameTag('x'));
        $world->setEntityTier($entity->id, ActivityTier::Dormant);

        $world->setEntityTier($entity->id, ActivityTier::Visible);

        self::assertSame(ActivityTier::Visible, $world->entityTier($entity->id));
        self::assertArrayHasKey($entity->id, $world->componentPool(NameTag::class));
    }

    public function testSetEntityTierOnADeadEntityIsANoop(): void
    {
        $world = new World();
        $world->setEntityTier(999, ActivityTier::Far);
        self::assertFalse($world->isFar(999));
    }

    public function testSetEntitiesTierBulkForm(): void
    {
        $world = new World();
        $a = $world->createEntity();
        $b = $world->createEntity();

        $world->setEntitiesTier([$a->id, $b->id], ActivityTier::Far);

        self::assertTrue($world->isFar($a->id));
        self::assertTrue($world->isFar($b->id));
    }

    public function testDestroyEntityClearsItsFarMembership(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $world->setEntityTier($entity->id, ActivityTier::Far);
        $id = $entity->id;

        $world->destroyEntity($id);

        self::assertFalse($world->isFar($id));
    }

    public function testClearResetsFarMembership(): void
    {
        $world = new World();
        $entity = $world->createEntity();
        $world->setEntityTier($entity->id, ActivityTier::Far);

        $world->clear();

        self::assertFalse($world->isFar($entity->id));
    }

    public function testPassiveComponentRegistryIsAPureStub(): void
    {
        $world = new World();
        self::assertFalse($world->isPassiveComponent(NameTag::class));

        $world->registerPassiveComponent(NameTag::class);

        self::assertTrue($world->isPassiveComponent(NameTag::class));
        // It is a class-level registry, not entity-keyed - world->clear()
        // must not forget it (mirrors MeshRegistry/MaterialRegistry staying
        // live across a world reset).
        $world->clear();
        self::assertTrue($world->isPassiveComponent(NameTag::class));
    }
}
