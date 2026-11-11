<?php

declare(strict_types=1);

namespace PHPolygon\Component;

/**
 * Change-notification sink for {@see Transform3D}'s set hooks.
 *
 * A Transform3D marks its own entity id here when position, rotation, scale
 * or parentEntityId is assigned, instead of Transform3DSystem walking the
 * whole component pool every tick to find out what moved. One instance is
 * shared by every Transform3D bound to the same Transform3DSystem - the
 * system hands it out through {@see \PHPolygon\ECS\World::observe()}'s
 * attach callback (see Transform3D::bindDirtyQueue()).
 */
final class TransformDirtyQueue
{
    /** @var array<int, true> */
    private array $ids = [];

    public function mark(int $entityId): void
    {
        $this->ids[$entityId] = true;
    }

    /**
     * Returns the marked ids and empties the queue.
     *
     * @return array<int, true>
     */
    public function drain(): array
    {
        $ids = $this->ids;
        $this->ids = [];
        return $ids;
    }

    public function clear(): void
    {
        $this->ids = [];
    }
}
