<?php

declare(strict_types=1);

namespace PHPolygon\Navigation;

/**
 * Min-heap optimized for A* pathfinding.
 *
 * Supports decrease-key by node ID, which SplPriorityQueue cannot do.
 * Node IDs are NavMesh polygon indices.
 */
class BinaryHeap
{
    /** @var array{int, float}[] Heap entries: [nodeId, priority]. */
    private array $heap = [];

    /** @var array<int, int> nodeId -> heap index for decrease-key. */
    private array $index = [];

    public function isEmpty(): bool
    {
        return count($this->heap) === 0;
    }

    public function count(): int
    {
        return count($this->heap);
    }

    public function contains(int $nodeId): bool
    {
        return isset($this->index[$nodeId]);
    }

    public function insert(int $nodeId, float $priority): void
    {
        $pos = count($this->heap);
        $this->heap[$pos] = [$nodeId, $priority];
        $this->index[$nodeId] = $pos;
        $this->bubbleUp($pos);
    }

    /**
     * Extract the node with the lowest priority (cost).
     *
     * @return array{int, float}|null [nodeId, priority] or null if empty.
     */
    public function extractMin(): ?array
    {
        if (count($this->heap) === 0) {
            return null;
        }

        $min = $this->heap[0];
        $last = count($this->heap) - 1;

        if ($last > 0) {
            $this->swap(0, $last);
        }

        unset($this->index[$min[0]]);
        array_pop($this->heap);

        if (count($this->heap) > 0) {
            $this->sinkDown(0);
        }

        return $min;
    }

    public function decreaseKey(int $nodeId, float $newPriority): void
    {
        if (!isset($this->index[$nodeId])) {
            return;
        }

        $pos = $this->index[$nodeId];
        if ($newPriority >= $this->heap[$pos][1]) {
            return;
        }

        $this->heap[$pos][1] = $newPriority;
        $this->bubbleUp($pos);
    }

    private function bubbleUp(int $pos): void
    {
        while ($pos > 0) {
            $parent = ($pos - 1) >> 1;
            if ($this->heap[$pos][1] < $this->heap[$parent][1]) {
                $this->swap($pos, $parent);
                $pos = $parent;
            } else {
                break;
            }
        }
    }

    private function sinkDown(int $pos): void
    {
        $size = count($this->heap);

        while (true) {
            $left = ($pos << 1) + 1;
            $right = $left + 1;
            $smallest = $pos;

            if ($left < $size && $this->heap[$left][1] < $this->heap[$smallest][1]) {
                $smallest = $left;
            }
            if ($right < $size && $this->heap[$right][1] < $this->heap[$smallest][1]) {
                $smallest = $right;
            }

            if ($smallest !== $pos) {
                $this->swap($pos, $smallest);
                $pos = $smallest;
            } else {
                break;
            }
        }
    }

    private function swap(int $a, int $b): void
    {
        $nodeA = $this->heap[$a][0];
        $nodeB = $this->heap[$b][0];

        $this->index[$nodeA] = $b;
        $this->index[$nodeB] = $a;

        [$this->heap[$a], $this->heap[$b]] = [$this->heap[$b], $this->heap[$a]];
    }
}
