<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

/**
 * Broadphase for STATIC collider boxes of a world that streams in cells: the
 * boxes of a cell arrive as one packed string, live and die with the cell, and
 * only the cells marked active take part in queries. A player looking at the
 * boxes around them pays for the few cells nearby, not for every box of the
 * world.
 *
 * It knows no world geometry: a "cell" is whatever name the caller gives a
 * batch of boxes, and each cell's own AABB is measured from its boxes. The
 * caller decides which cells are active ({@see setActive()}, usually "the
 * cells within physics range of the player") and gets back what changed.
 * Within a cell a lazily built uniform bucket grid on XZ ({@see $bucketSize})
 * narrows the candidates; a box that spans more than {@see MAX_BUCKETS_PER_BOX}
 * buckets (a ground slab, a long wall) goes on a per-cell list that every
 * query of that cell checks.
 *
 * Boxes are identified by a caller-chosen uint32 id; a query returns ids, the
 * caller maps them back to whatever it keeps per collider. Coordinates are
 * stored as float32.
 *
 * Cost (measured 2026-11-14, `benchmarks/streaming-glue.php colliders`): the
 * index of a cell is built on the first query that reaches it, or by
 * {@see prepare()}, at roughly 0.6 microseconds per box on the calling thread;
 * a query over nine active cells of 2000 boxes each takes a few microseconds.
 * {@see prepare()} a big cell inside the streaming time budget, not in the
 * middle of a physics step.
 *
 * What it is not: a replacement for the engine's per-entity collision, which
 * scans the ECS world (`RigidBody3DSystem::resolveStaticCollisions()` walks
 * every static `BoxCollider3D`; nothing in the engine routes it through this
 * class yet). Games that stream their colliders as packed boxes query this
 * directly, and an ECS integration would feed these queries into those loops.
 *
 * Versus {@see \PHPolygon\Visibility\SpatialIndex}: that one indexes bounding
 * SPHERES of render entries by handle in fixed 32 m cells for visibility
 * classification, with no activation and no box queries. Versus
 * {@see SpatialHash3D}: a per-frame pair finder that is cleared and refilled.
 */
final class CellColliderGrid
{
    /** Bytes per box in the packed format: 6 f32 + one u32 id. */
    public const int RECORD_BYTES = 28;

    /** A box covering more buckets than this is kept on the cell's "large" list. */
    public const int MAX_BUCKETS_PER_BOX = 16;

    /** @var array<string, ColliderCell> */
    private array $cells = [];

    /** @var array<string, true> */
    private array $active = [];

    public function __construct(
        private readonly float $bucketSize = 8.0,
    ) {
        if ($bucketSize <= 0.0) {
            throw new \InvalidArgumentException('CellColliderGrid: bucketSize must be positive.');
        }
    }

    /**
     * The packed form {@see add()} takes: n boxes as `n * 24` bytes of f32
     * (min xyz, max xyz per box) followed by `n * 4` bytes of u32 ids. Meant to
     * run in a worker next to the geometry bake.
     *
     * @param iterable<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> $boxes id, min xyz, max xyz
     */
    public static function packBoxes(iterable $boxes): string
    {
        $floats = [];
        $ids = [];
        foreach ($boxes as $b) {
            if ($b[1] > $b[4] || $b[2] > $b[5] || $b[3] > $b[6]) {
                throw new \InvalidArgumentException("CellColliderGrid: box {$b[0]} has min above max.");
            }
            $ids[] = $b[0];
            array_push($floats, $b[1], $b[2], $b[3], $b[4], $b[5], $b[6]);
        }

        return ($floats === [] ? '' : pack('g*', ...$floats)) . ($ids === [] ? '' : pack('V*', ...$ids));
    }

    /**
     * Put the boxes of a cell into the grid, replacing what the cell held. It
     * takes part in queries once active (a cell already marked active is
     * active on arrival). Cheap: the bucket index is built on first use (or by
     * {@see prepare()}).
     *
     * @return int number of boxes
     */
    public function add(string $cell, string $packedBoxes): int
    {
        $len = strlen($packedBoxes);
        if ($len % self::RECORD_BYTES !== 0) {
            throw new \InvalidArgumentException("CellColliderGrid: {$len} bytes is not a whole number of 28-byte records.");
        }
        $count = intdiv($len, self::RECORD_BYTES);
        $this->cells[$cell] = new ColliderCell($packedBoxes, $count);

        return $count;
    }

    /** Drop a cell and its boxes (the cell streamed out). */
    public function remove(string $cell): bool
    {
        $had = isset($this->cells[$cell]);
        unset($this->cells[$cell], $this->active[$cell]);

        return $had;
    }

    /**
     * Make exactly these cells the active ones (cells that are not loaded are
     * remembered as active and take effect when they arrive).
     *
     * @param iterable<string> $cells
     * @return array{activated: list<string>, deactivated: list<string>}
     */
    public function setActive(iterable $cells): array
    {
        $next = [];
        foreach ($cells as $c) {
            $next[$c] = true;
        }
        $activated = array_keys(array_diff_key($next, $this->active));
        $deactivated = array_keys(array_diff_key($this->active, $next));
        $this->active = $next;

        return ['activated' => $activated, 'deactivated' => $deactivated];
    }

    public function isActive(string $cell): bool
    {
        return isset($this->active[$cell]) && isset($this->cells[$cell]);
    }

    public function has(string $cell): bool
    {
        return isset($this->cells[$cell]);
    }

    /** Build the bucket index of a cell now, so no query pays for it (a streamer can do this inside its own time budget). */
    public function prepare(string $cell): void
    {
        $c = $this->cells[$cell] ?? null;
        if ($c !== null && !$c->built) {
            $this->build($c);
        }
    }

    /**
     * Ids of the boxes of ACTIVE cells that overlap the query box (touching
     * faces do not overlap), each once, in no particular order.
     *
     * @return list<int>
     */
    public function query(float $minX, float $minY, float $minZ, float $maxX, float $maxY, float $maxZ): array
    {
        $out = [];
        $inv = 1.0 / $this->bucketSize;
        foreach ($this->active as $name => $_) {
            $c = $this->cells[$name] ?? null;
            if ($c === null || $c->count === 0) {
                continue;
            }
            if (!$c->built) {
                $this->build($c);
            }
            if ($maxX <= $c->minX || $minX >= $c->maxX
                || $maxY <= $c->minY || $minY >= $c->maxY
                || $maxZ <= $c->minZ || $minZ >= $c->maxZ
            ) {
                continue;
            }
            $w = $c->width;
            $x0 = max(0, (int) floor(($minX - $c->minX) * $inv));
            $x1 = min($w - 1, (int) floor(($maxX - $c->minX) * $inv));
            $z0 = max(0, (int) floor(($minZ - $c->minZ) * $inv));
            $z1 = min($c->depth - 1, (int) floor(($maxZ - $c->minZ) * $inv));
            // Candidate indices, deduplicated by being array keys: a box sits in every bucket it touches.
            $candidates = array_flip($c->large);
            for ($bz = $z0; $bz <= $z1; $bz++) {
                for ($bx = $x0; $bx <= $x1; $bx++) {
                    foreach ($c->buckets[$bz * $w + $bx] ?? [] as $i) {
                        $candidates[$i] = true;
                    }
                }
            }
            $box = $c->box;
            foreach ($candidates as $i => $_) {
                $o = $i * 6;
                if ($box[$o] < $maxX && $box[$o + 3] > $minX
                    && $box[$o + 1] < $maxY && $box[$o + 4] > $minY
                    && $box[$o + 2] < $maxZ && $box[$o + 5] > $minZ
                ) {
                    $out[] = $c->ids[$i];
                }
            }
        }

        return $out;
    }

    public function cellCount(): int
    {
        return count($this->cells);
    }

    public function activeCellCount(): int
    {
        return count(array_intersect_key($this->active, $this->cells));
    }

    /** Boxes in all loaded cells. */
    public function colliderCount(): int
    {
        $n = 0;
        foreach ($this->cells as $c) {
            $n += $c->count;
        }

        return $n;
    }

    /** Boxes in active, loaded cells: what a query can reach. */
    public function activeColliderCount(): int
    {
        $n = 0;
        foreach ($this->active as $name => $_) {
            $n += $this->cells[$name]->count ?? 0;
        }

        return $n;
    }

    private function build(ColliderCell $c): void
    {
        $n = $c->count;
        $box = $n === 0 ? [] : self::unpackFloats(substr($c->packed, 0, $n * 24));
        $ids = $n === 0 ? [] : self::unpackInts(substr($c->packed, $n * 24));

        $minX = $minY = $minZ = INF;
        $maxX = $maxY = $maxZ = -INF;
        for ($o = 0, $end = $n * 6; $o < $end; $o += 6) {
            if ($box[$o] < $minX) { $minX = $box[$o]; }
            if ($box[$o + 1] < $minY) { $minY = $box[$o + 1]; }
            if ($box[$o + 2] < $minZ) { $minZ = $box[$o + 2]; }
            if ($box[$o + 3] > $maxX) { $maxX = $box[$o + 3]; }
            if ($box[$o + 4] > $maxY) { $maxY = $box[$o + 4]; }
            if ($box[$o + 5] > $maxZ) { $maxZ = $box[$o + 5]; }
        }
        if ($n === 0) {
            $minX = $minY = $minZ = $maxX = $maxY = $maxZ = 0.0;
        }

        $inv = 1.0 / $this->bucketSize;
        // Offsets from the cell minimum are never negative, so a plain int cast floors them.
        $w = (int) (($maxX - $minX) * $inv) + 1;
        $depth = (int) (($maxZ - $minZ) * $inv) + 1;
        $buckets = [];
        $large = [];
        for ($i = 0; $i < $n; $i++) {
            $o = $i * 6;
            $x0 = (int) (($box[$o] - $minX) * $inv);
            $x1 = (int) (($box[$o + 3] - $minX) * $inv);
            $z0 = (int) (($box[$o + 2] - $minZ) * $inv);
            $z1 = (int) (($box[$o + 5] - $minZ) * $inv);
            if (($x1 - $x0 + 1) * ($z1 - $z0 + 1) > self::MAX_BUCKETS_PER_BOX) {
                $large[] = $i;
                continue;
            }
            for ($bz = $z0; $bz <= $z1; $bz++) {
                for ($bx = $x0; $bx <= $x1; $bx++) {
                    $buckets[$bz * $w + $bx][] = $i;
                }
            }
        }

        $c->box = $box;
        $c->ids = $ids;
        $c->buckets = $buckets;
        $c->large = $large;
        $c->minX = $minX;
        $c->minY = $minY;
        $c->minZ = $minZ;
        $c->maxX = $maxX;
        $c->maxY = $maxY;
        $c->maxZ = $maxZ;
        $c->width = $w;
        $c->depth = $depth;
        $c->built = true;
        // The packed source is no longer needed once indexed.
        $c->packed = '';
    }

    /** @return list<float> */
    private static function unpackFloats(string $bytes): array
    {
        $u = unpack('g*', $bytes);

        /** @var list<float> */
        return $u === false ? [] : array_values($u);
    }

    /** @return list<int> */
    private static function unpackInts(string $bytes): array
    {
        $u = unpack('V*', $bytes);

        /** @var list<int> */
        return $u === false ? [] : array_values($u);
    }
}
