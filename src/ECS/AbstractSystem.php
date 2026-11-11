<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

use PHPolygon\Activity\TierPolicy;

abstract class AbstractSystem implements SystemInterface
{
    public function register(World $world): void
    {
    }

    public function unregister(World $world): void
    {
    }

    public function update(World $world, float $dt): void
    {
    }

    public function render(World $world): void
    {
    }

    /**
     * Invoked by {@see World::clear()} after all entities are destroyed and the
     * id counter is reset. Systems that hold per-entity-id caches (transform
     * snapshots, spatial bins, lookup tables) must override this to drop them;
     * otherwise the cache associates stale data with recycled ids and produces
     * "not-dirty"-style ghosts on the next frame. Default is a no-op so systems
     * without such caches need not opt in.
     */
    public function onWorldClear(World $world): void
    {
    }

    /**
     * This system's declared interest in Far-tier entities (Phase 4 of the
     * visibility plan) - see {@see TierPolicy}. Default is "every tick, all
     * tiers": overriding this does NOT by itself change what update()/render()
     * iterate (they still read componentPool()/query(), which always
     * includes Far-tier entities) - it only matters once a Phase 6 driver
     * reads $hasFarCore to decide whether to hand this system's Far-tier
     * entities to a far core instead.
     */
    public function tierPolicy(): TierPolicy
    {
        return TierPolicy::everyTick();
    }
}
