<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

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
}
