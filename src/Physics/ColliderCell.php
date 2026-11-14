<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

/**
 * The boxes of one cell of a {@see CellColliderGrid}, packed until the first
 * query needs them and indexed (bucket grid on XZ) after that.
 *
 * @internal
 */
final class ColliderCell
{
    public bool $built = false;

    /** @var list<float> min xyz, max xyz per box (6 floats), once built */
    public array $box = [];

    /** @var list<int> box ids, once built */
    public array $ids = [];

    /** @var array<int, list<int>> bucket => box indices */
    public array $buckets = [];

    /** @var list<int> boxes too large for the bucket grid, tested by every query */
    public array $large = [];

    public float $minX = 0.0;
    public float $minY = 0.0;
    public float $minZ = 0.0;
    public float $maxX = 0.0;
    public float $maxY = 0.0;
    public float $maxZ = 0.0;

    /** Buckets along x and z. */
    public int $width = 1;
    public int $depth = 1;

    /**
     * @param string $packed `count * 24` bytes of f32 boxes followed by `count * 4` bytes of u32 ids; emptied once built
     */
    public function __construct(
        public string $packed,
        public readonly int $count,
    ) {}
}
