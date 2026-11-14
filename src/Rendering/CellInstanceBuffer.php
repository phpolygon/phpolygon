<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * One (cell, mesh, material) buffer of a {@see CellInstanceBuffers}: the live
 * matrix bytes, what has been admitted to the renderer so far, and the byte
 * range written since.
 *
 * @internal
 * @phpstan-type Extents array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}
 */
final class CellInstanceBuffer
{
    /** Live matrices, `count * 64` bytes. */
    public string $data = '';

    public int $count = 0;

    /** @var Extents|null translation box and largest stretch of the live instances (possibly larger than needed after removals) */
    public ?array $extents = null;

    /** Removals and replacements since the extents were last measured. */
    public int $loose = 0;

    /** Bytes `[dirtyFrom, dirtyTo)` changed since the last admission; empty when `dirtyTo <= dirtyFrom`. */
    public int $dirtyFrom = 0;

    public int $dirtyTo = 0;

    public int $admittedCount = 0;

    /** Snapshot of the data at admission (packed path only; the sink path keeps it on the GPU side). */
    public ?string $admittedData = null;

    /** @var Extents|null */
    public ?array $admittedExtents = null;

    /** Largest size the sink buffer was asked to have. */
    public int $capacity = 0;

    public function __construct(
        public readonly string $cell,
        public readonly string $meshId,
        public readonly string $materialId,
    ) {}

    public function key(): string
    {
        return $this->cell . '|' . $this->meshId . '|' . $this->materialId;
    }

    public function dirtyBytes(): int
    {
        return max(0, $this->dirtyTo - $this->dirtyFrom);
    }

    public function markDirty(int $from, int $to): void
    {
        if ($this->dirtyTo <= $this->dirtyFrom) {
            $this->dirtyFrom = $from;
            $this->dirtyTo = $to;

            return;
        }
        $this->dirtyFrom = min($this->dirtyFrom, $from);
        $this->dirtyTo = max($this->dirtyTo, $to);
    }

    public function clearDirty(): void
    {
        $this->dirtyFrom = 0;
        $this->dirtyTo = 0;
    }
}
