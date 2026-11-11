<?php

declare(strict_types=1);

namespace PHPolygon\Component;

/**
 * Change-notification sink for {@see MeshRenderer}'s set hooks on $visible
 * and $meshId - the two fields that change which entry (if any)
 * {@see \PHPolygon\Visibility\VisibilityService} keeps for an entity:
 * $visible toggling it in/out of the index, $meshId changing its bounding
 * sphere. Mirrors {@see TransformDirtyQueue} exactly - one instance shared by
 * every MeshRenderer bound to the same VisibilityService, handed out through
 * {@see \PHPolygon\ECS\World::observe()}'s attach callback (see
 * MeshRenderer::bindChangeQueue()).
 */
final class MeshRendererChangeQueue
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
