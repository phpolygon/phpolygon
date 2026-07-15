<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\World;
use PHPolygon\System\TerrainRegenerationSystem;
use PHPolygon\Terrain\RegenerableTerrain;
use PHPUnit\Framework\TestCase;

class TerrainRegenerationSystemTest extends TestCase
{
    public function testDoesNotRebuildOnBaselineFrame(): void
    {
        $world = new World();
        $world->addSystem(new TerrainRegenerationSystem([FakeTerrain::class]));
        $terrain = new FakeTerrain();
        $world->createEntity()->attach($terrain);

        $world->update(0.016);

        $this->assertSame(0, $terrain->rebuildCount, 'unchanged terrain must not rebuild');
    }

    public function testRebuildsOnceAfterDebounceWhenAPropertyChanges(): void
    {
        $world = new World();
        $world->addSystem(new TerrainRegenerationSystem([FakeTerrain::class], debounceSeconds: 0.15));
        $terrain = new FakeTerrain();
        $world->createEntity()->attach($terrain);

        $world->update(0.016); // adopt baseline

        $terrain->height = 5.0;
        $world->update(0.016); // change seen, debounce not elapsed yet
        $this->assertSame(0, $terrain->rebuildCount);

        $world->update(0.2); // debounce elapsed
        $this->assertSame(1, $terrain->rebuildCount);
        $this->assertSame(5.0, $terrain->rebuiltAtHeight);

        $world->update(0.2); // nothing changed since
        $this->assertSame(1, $terrain->rebuildCount, 'idle frames must not rebuild again');
    }

    public function testContinuousChangesCollapseIntoOneRebuild(): void
    {
        $world = new World();
        $world->addSystem(new TerrainRegenerationSystem([FakeTerrain::class], debounceSeconds: 0.15));
        $terrain = new FakeTerrain();
        $world->createEntity()->attach($terrain);

        $world->update(0.016); // baseline

        // Simulate an active slider drag: value moves every frame, faster than
        // the debounce window — nothing should rebuild mid-drag.
        for ($i = 0; $i < 10; $i++) {
            $terrain->height += 1.0;
            $world->update(0.05);
        }
        $this->assertSame(0, $terrain->rebuildCount, 'no rebuild while the value is still moving');

        $world->update(0.2); // let it settle
        $this->assertSame(1, $terrain->rebuildCount, 'exactly one rebuild once it settles');
    }

    public function testOnWorldClearDropsPerEntityState(): void
    {
        $world = new World();
        $system = new TerrainRegenerationSystem([FakeTerrain::class], debounceSeconds: 0.15);
        $world->addSystem($system);

        $terrain = new FakeTerrain();
        $world->createEntity()->attach($terrain);
        $world->update(0.016); // baseline recorded against this entity id

        $system->onWorldClear($world);

        // A fresh terrain reusing the same id must be treated as a new baseline
        // (no rebuild), not compared against the cleared entity's signature.
        $fresh = new FakeTerrain();
        $fresh->height = 99.0;
        $world->createEntity()->attach($fresh);
        $world->update(0.016);
        $this->assertSame(0, $fresh->rebuildCount);
    }
}

#[Serializable]
class FakeTerrain extends AbstractComponent implements RegenerableTerrain
{
    #[Property]
    public float $height = 1.0;

    /** Plain fields (no #[Property]) so they stay out of the change signature. */
    public int $rebuildCount = 0;

    public float $rebuiltAtHeight = 0.0;

    public function rebuild(World $world, int $entityId): void
    {
        $this->rebuildCount++;
        $this->rebuiltAtHeight = $this->height;
    }
}
