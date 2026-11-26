<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\TransformDirtyQueue;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * Persistent index of every {@see BoxCollider3D} in a World, for {@see Physics3DSystem}.
 *
 * Collecting all box colliders every tick cost one world-matrix check per collider (8,500 boxes: several
 * milliseconds per tick) and every character then walked the whole list. This index keeps the collision
 * data of each box (aligned boxes as an AABB, turned ones as an {@see OrientedBox}, the same data that
 * stays cached on the component for {@see Raycaster3D::segmentBlocked()}) and a uniform XZ grid over it, and
 * changes only what changed:
 *
 *  - a world-matrix assignment ({@see Transform3D::watchWorldMatrix()}) re-reads that one collider;
 *  - attach and detach arrive through {@see World::observe()};
 *  - dormancy ({@see World::dormancyVersion()}) rebuilds the whole index once: it fires no attach, and a
 *    woken entity moves to the end of the pool, which is what the collider order follows;
 *  - `isTrigger` is NOT tracked. Triggers stay in the index with their caches built and are filtered when a
 *    query hands out candidates, so flipping a thousand colliders between trigger and solid costs nothing.
 *    The price is that the caches of triggers are built with the first sync instead of at the flip.
 *
 * Queries return the same entries, in the same order (pool order: attach order, a woken entity last), that
 * the old per-tick scan produced; only entries outside the asked XZ rectangle are missing. Resolution is
 * sequential (each push moves the capsule for the next box), so the order is part of the behaviour. Boxes
 * larger than {@see MAX_CELLS} grid cells (a floor slab) are kept apart and returned by every query.
 * With `$broadphase = false` every query returns everything: the exact old behaviour, for tests and for
 * ruling the grid out.
 *
 * @phpstan-type BoxEntry array{
 *     seq: int, kind: int, entityId: int, collider: BoxCollider3D,
 *     min: Vec3, max: Vec3, box: ?OrientedBox, cells: ?list<int>,
 * }
 * @phpstan-type AabbEntry array{
 *     seq: int, kind: int, entityId: int, collider: BoxCollider3D,
 *     min: Vec3, max: Vec3, box: null, cells: ?list<int>,
 * }
 * @phpstan-type RotatedEntry array{
 *     seq: int, kind: int, entityId: int, collider: BoxCollider3D,
 *     min: Vec3, max: Vec3, box: OrientedBox, cells: ?list<int>,
 * }
 */
final class BoxColliderIndex
{
    /** Grid cell edge (m). Boxes of a built world are a few metres; a character reads a handful of cells. */
    public const float CELL_SIZE = 8.0;

    /** A box covering more cells than this is not put into the grid but into the list every query includes. */
    public const int MAX_CELLS = 64;

    private const int KIND_AABB = 0;
    private const int KIND_ROTATED = 1;

    /**
     * The index bound to each world (spl_object_id of the world => weak world, weak index), found by
     * {@see forWorld()}. Weak on both sides: the physics system owns the index, the engine the world.
     *
     * @var array<int, array{\WeakReference<World>, \WeakReference<self>}>
     */
    private static array $byWorld = [];

    private ?World $world = null;

    /** World-matrix assignments and attach/detach since the last sync (entity ids). */
    private readonly TransformDirtyQueue $dirty;

    private int $dormancyVersion = -1;
    private int $nextSeq = 0;
    private int $rotatedCount = 0;

    /**
     * Entry per entity id. Insertion order is seq order (a rebuild walks the pool, an attach appends, a
     * detach removes), which brute-force mode relies on.
     *
     * @var array<int, BoxEntry>
     */
    private array $entries = [];

    /** @var array<int, array<int, array<int, BoxEntry>>> kind => cell key => seq => entry */
    private array $cells = [self::KIND_AABB => [], self::KIND_ROTATED => []];

    /** @var array<int, array<int, BoxEntry>> kind => seq => entry, boxes too big for the grid */
    private array $large = [self::KIND_AABB => [], self::KIND_ROTATED => []];

    private int $rebuilds = 0;
    private int $refreshes = 0;

    public function __construct(private readonly bool $broadphase = true)
    {
        $this->dirty = new TransformDirtyQueue();
    }

    /**
     * Bring the index up to date with $world. O(changed colliders) when nothing structural happened.
     */
    public function sync(World $world): void
    {
        if ($this->world !== $world) {
            $this->bind($world);
            $this->rebuild($world);
            return;
        }
        if ($world->dormancyVersion() !== $this->dormancyVersion) {
            $this->rebuild($world);
            return;
        }

        foreach ($this->dirty->drain() as $id => $_) {
            $collider = $world->tryGetComponent($id, BoxCollider3D::class);
            $transform = $world->tryGetComponent($id, Transform3D::class);
            if ($collider instanceof BoxCollider3D && $transform instanceof Transform3D && !$world->isDormant($id)) {
                $this->refresh($id, $collider, $transform);
            } else {
                $this->remove($id);
            }
        }
    }

    /**
     * Solid axis-aligned boxes whose footprint touches the XZ rectangle, in pool order.
     *
     * @return list<AabbEntry>
     */
    public function boxesIn(float $minX, float $minZ, float $maxX, float $maxZ): array
    {
        /** @var list<AabbEntry> $found */
        $found = $this->query(self::KIND_AABB, $minX, $minZ, $maxX, $maxZ);

        return $found;
    }

    /**
     * Solid turned boxes whose hull footprint touches the XZ rectangle, in pool order.
     *
     * @return list<RotatedEntry>
     */
    public function rotatedIn(float $minX, float $minZ, float $maxX, float $maxZ): array
    {
        /** @var list<RotatedEntry> $found */
        $found = $this->query(self::KIND_ROTATED, $minX, $minZ, $maxX, $maxZ);

        return $found;
    }

    /**
     * Solid boxes (aligned and turned) whose grid cells the segment from → to passes through (cells
     * touched by its XZ run, padded by a hair), in no particular order: the candidates for a line-of-sight
     * test, a superset of the boxes whose footprint the segment crosses. With `$broadphase = false`, or a
     * segment longer than the grid is worth, every solid box.
     *
     * @return list<BoxEntry>
     */
    public function segmentCandidates(Vec3 $from, Vec3 $to): array
    {
        $out = [];
        $hits = [];
        $pad = 1.0e-6;
        $xa = min($from->x, $to->x) - $pad;
        $xb = max($from->x, $to->x) + $pad;
        $za = min($from->z, $to->z) - $pad;
        $zb = max($from->z, $to->z) + $pad;
        $inv = 1.0 / self::CELL_SIZE;

        $x0 = $x1 = $z0 = $z1 = 0;
        $everything = !$this->broadphase || !is_finite($xa + $xb + $za + $zb);
        if (!$everything) {
            $x0 = (int) floor($xa * $inv);
            $x1 = (int) floor($xb * $inv);
            $z0 = (int) floor($za * $inv);
            $z1 = (int) floor($zb * $inv);
            $everything = ($x1 - $x0 + 1) + ($z1 - $z0 + 1) > 4096;
        }
        if ($everything) {
            foreach ($this->entries as $entry) {
                if (!$entry['collider']->isTrigger) {
                    $out[] = $entry;
                }
            }

            return $out;
        }

        foreach ([self::KIND_AABB, self::KIND_ROTATED] as $kind) {
            foreach ($this->large[$kind] as $seq => $entry) {
                $hits[$seq] = $entry;
            }
        }

        $dx = $to->x - $from->x;
        $dz = $to->z - $from->z;
        for ($cx = $x0; $cx <= $x1; $cx++) {
            // The part of the segment inside this column of cells: its z run picks the cells.
            $zLo = $za;
            $zHi = $zb;
            if ($x1 > $x0 && abs($dx) > 1.0e-12) {
                $t1 = max(0.0, min(1.0, (max($xa, $cx * self::CELL_SIZE - $pad) - $from->x) / $dx));
                $t2 = max(0.0, min(1.0, (min($xb, ($cx + 1) * self::CELL_SIZE + $pad) - $from->x) / $dx));
                $zs1 = $from->z + $t1 * $dz;
                $zs2 = $from->z + $t2 * $dz;
                $zLo = min($zs1, $zs2) - $pad;
                $zHi = max($zs1, $zs2) + $pad;
            }
            $base = $cx * 1048576;
            for ($cz = (int) floor($zLo * $inv), $czEnd = (int) floor($zHi * $inv); $cz <= $czEnd; $cz++) {
                foreach ([self::KIND_AABB, self::KIND_ROTATED] as $kind) {
                    if (isset($this->cells[$kind][$base + $cz])) {
                        foreach ($this->cells[$kind][$base + $cz] as $seq => $entry) {
                            $hits[$seq] = $entry;
                        }
                    }
                }
            }
        }

        foreach ($hits as $entry) {
            if (!$entry['collider']->isTrigger) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Can this index answer for $world right now: it is the one bound to it, and no entity went dormant or
     * woke since its last sync (which would leave entries the pool no longer has)?
     */
    public function isCurrentFor(World $world): bool
    {
        return $this->world === $world && $this->dormancyVersion === $world->dormancyVersion();
    }

    /**
     * The index a {@see Physics3DSystem} keeps for $world, or null when no physics system has synced one:
     * what lets {@see Raycaster3D::segmentBlocked()} answer from the grid without being handed the system.
     */
    public static function forWorld(World $world): ?self
    {
        $entry = self::$byWorld[spl_object_id($world)] ?? null;
        if ($entry === null || $entry[0]->get() !== $world) {
            return null;   // none, or an id a collected world left behind
        }

        return $entry[1]->get();
    }

    private static function register(World $world, self $index): void
    {
        foreach (self::$byWorld as $id => [$worldRef, $indexRef]) {
            if ($worldRef->get() === null || $indexRef->get() === null) {
                unset(self::$byWorld[$id]);
            }
        }
        self::$byWorld[spl_object_id($world)] = [\WeakReference::create($world), \WeakReference::create($index)];
    }

    /** Is there any turned box (solid or trigger)? False lets a caller skip the oriented-box pass. */
    public function hasRotated(): bool
    {
        return $this->rotatedCount > 0;
    }

    public function isBroadphase(): bool
    {
        return $this->broadphase;
    }

    /**
     * Counters for tests and benchmarks.
     *
     * @return array{rebuilds: int, refreshes: int, entries: int, large: int}
     */
    public function stats(): array
    {
        return [
            'rebuilds' => $this->rebuilds,
            'refreshes' => $this->refreshes,
            'entries' => count($this->entries),
            'large' => count($this->large[self::KIND_AABB]) + count($this->large[self::KIND_ROTATED]),
        ];
    }

    private function bind(World $world): void
    {
        $this->world = $world;
        $dirty = $this->dirty;
        self::register($world, $this);

        $world->observe(
            BoxCollider3D::class,
            function (int $id) use ($world, $dirty): void {
                if ($this->world === $world) {
                    $dirty->mark($id);
                }
            },
            function (int $id) use ($world): void {
                if ($this->world === $world) {
                    $this->remove($id);
                }
            },
        );
        $world->observe(
            Transform3D::class,
            function (int $id) use ($world, $dirty): void {
                if ($this->world === $world) {
                    $dirty->mark($id);
                }
            },
            function (int $id, mixed $component) use ($world, $dirty): void {
                if ($this->world === $world) {
                    if ($component instanceof Transform3D) {
                        $component->unwatchWorldMatrix();
                    }
                    $dirty->mark($id);
                }
            },
        );
    }

    /** Throw everything away and index the pool again, in pool order. */
    private function rebuild(World $world): void
    {
        $this->rebuilds++;
        $this->entries = [];
        $this->cells = [self::KIND_AABB => [], self::KIND_ROTATED => []];
        $this->large = [self::KIND_AABB => [], self::KIND_ROTATED => []];
        $this->nextSeq = 0;
        $this->rotatedCount = 0;
        $this->dormancyVersion = $world->dormancyVersion();
        $this->dirty->clear();

        /** @var array<int, BoxCollider3D> $pool */
        $pool = $world->componentPool(BoxCollider3D::class);
        foreach ($pool as $id => $collider) {
            $transform = $world->tryGetComponent($id, Transform3D::class);
            if ($transform instanceof Transform3D) {
                $this->refresh($id, $collider, $transform);
            }
        }
    }

    /**
     * Make the entry of one collider match its component and transform: the same cache the per-tick scan
     * kept on the component (keyed on the world-matrix snapshot), then the grid cells.
     */
    private function refresh(int $id, BoxCollider3D $collider, Transform3D $transform): void
    {
        $transform->watchWorldMatrix($id, $this->dirty);
        $worldMatrix = $transform->getWorldMatrix();
        $entry = $this->entries[$id] ?? null;

        // Same (immutable) Mat4 object as when the cache was built: the box did not move.
        if ($entry !== null && $entry['collider'] === $collider
            && $worldMatrix === $collider->lastWorldMatrix
            && $collider->cachedWorldAabb !== null
            && (!$collider->cachedRotated || $collider->cachedOriented !== null)
        ) {
            return;
        }
        $this->refreshes++;

        $this->updateCaches($collider, $transform, $worldMatrix);

        if ($collider->cachedRotated) {
            $box = $collider->cachedOriented;
            if ($box === null) {
                return;
            }
            $kind = self::KIND_ROTATED;
            $min = $box->min;
            $max = $box->max;
        } else {
            $aabb = $collider->cachedWorldAabb;
            if ($aabb === null) {
                return;
            }
            $box = null;
            $kind = self::KIND_AABB;
            $min = $aabb['min'];
            $max = $aabb['max'];
        }

        if ($entry !== null) {
            $seq = $entry['seq'];
            $this->unplace($entry);
        } else {
            $seq = $this->nextSeq++;
        }
        $this->place($id, $seq, $kind, $collider, $min, $max, $box);
    }

    /**
     * The cache logic of the old per-tick collect, unchanged: rotation probe, AABB / oriented box / 12-triangle
     * BVH keyed on the world-matrix values, written to the component for {@see Raycaster3D}. Built for triggers
     * too, so a collider that turns solid needs nothing.
     */
    private function updateCaches(BoxCollider3D $collider, Transform3D $transform, Mat4 $worldMatrix): void
    {
        // The vector part alone decides: |w| stays within 0.001 of 1 up to 5 degrees, and a long box turned
        // that little has a hull metres deeper than itself.
        $rot = $transform->rotation;
        $isRotated = abs($rot->x) > 0.001 || abs($rot->y) > 0.001 || abs($rot->z) > 0.001;

        $worldMatrixArr = $worldMatrix->toArray();
        $matrixChanged = $collider->lastWorldMatrixArr === null
            || !Physics3DSystem::matrixArrayEquals($worldMatrixArr, $collider->lastWorldMatrixArr);

        // The 12-triangle BVH of a turned box only serves Raycaster3D::segmentBlocked(), so it is not built
        // here: a moved box drops it, BoxCollider3D::worldBvh() builds it from lastWorldMatrix when asked.
        if ($isRotated) {
            if ($matrixChanged || $collider->cachedWorldAabb === null || $collider->cachedOriented === null) {
                $collider->bvh = null;
                $collider->cachedWorldAabb = $collider->getWorldAABB($worldMatrix);
                $collider->cachedOriented = OrientedBox::fromCollider($collider, $worldMatrix);
                $collider->lastWorldMatrixArr = $worldMatrixArr;
            }
        } elseif ($matrixChanged || $collider->cachedWorldAabb === null) {
            $collider->bvh = null;
            $collider->cachedWorldAabb = $collider->getWorldAABB($worldMatrix);
            $collider->lastWorldMatrixArr = $worldMatrixArr;
        }
        $collider->lastWorldMatrix = $worldMatrix;
        $collider->cachedRotated = $isRotated;
    }

    private function place(int $id, int $seq, int $kind, BoxCollider3D $collider, Vec3 $min, Vec3 $max, ?OrientedBox $box): void
    {
        $cells = self::cellsOf($min, $max);
        $entry = [
            'seq' => $seq,
            'kind' => $kind,
            'entityId' => $id,
            'collider' => $collider,
            'min' => $min,
            'max' => $max,
            'box' => $box,
            'cells' => $cells,
        ];
        $this->entries[$id] = $entry;
        if ($kind === self::KIND_ROTATED) {
            $this->rotatedCount++;
        }
        if ($cells === null) {
            $this->large[$kind][$seq] = $entry;
            return;
        }
        foreach ($cells as $key) {
            $this->cells[$kind][$key][$seq] = $entry;
        }
    }

    /**
     * @param BoxEntry $entry
     */
    private function unplace(array $entry): void
    {
        $kind = $entry['kind'];
        $seq = $entry['seq'];
        if ($kind === self::KIND_ROTATED) {
            $this->rotatedCount--;
        }
        if ($entry['cells'] === null) {
            unset($this->large[$kind][$seq]);
        } else {
            foreach ($entry['cells'] as $key) {
                unset($this->cells[$kind][$key][$seq]);
                if ($this->cells[$kind][$key] === []) {
                    unset($this->cells[$kind][$key]);
                }
            }
        }
    }

    private function remove(int $id): void
    {
        if (isset($this->entries[$id])) {
            $this->unplace($this->entries[$id]);
            unset($this->entries[$id]);
        }
    }

    /**
     * Grid cell keys the box covers, or null when it covers more than {@see MAX_CELLS} (or is not finite).
     *
     * @return list<int>|null
     */
    private static function cellsOf(Vec3 $min, Vec3 $max): ?array
    {
        if (!is_finite($min->x + $min->z + $max->x + $max->z)) {
            return null;
        }
        $inv = 1.0 / self::CELL_SIZE;
        $x0 = (int) floor($min->x * $inv);
        $x1 = (int) floor($max->x * $inv);
        $z0 = (int) floor($min->z * $inv);
        $z1 = (int) floor($max->z * $inv);
        if (($x1 - $x0 + 1) * ($z1 - $z0 + 1) > self::MAX_CELLS) {
            return null;
        }
        $keys = [];
        for ($x = $x0; $x <= $x1; $x++) {
            for ($z = $z0; $z <= $z1; $z++) {
                $keys[] = $x * 1048576 + $z;
            }
        }

        return $keys;
    }

    /**
     * @return list<BoxEntry>
     */
    private function query(int $kind, float $minX, float $minZ, float $maxX, float $maxZ): array
    {
        $out = [];
        if (!$this->broadphase) {
            foreach ($this->entries as $entry) {
                if ($entry['kind'] === $kind && !$entry['collider']->isTrigger) {
                    $out[] = $entry;
                }
            }

            return $out;
        }

        $inv = 1.0 / self::CELL_SIZE;
        $x0 = (int) floor($minX * $inv);
        $x1 = (int) floor($maxX * $inv);
        $z0 = (int) floor($minZ * $inv);
        $z1 = (int) floor($maxZ * $inv);

        $hits = [];
        if (($x1 - $x0 + 1) * ($z1 - $z0 + 1) > 4096) {
            // A rectangle wider than the grid is worth: look at every entry of the kind.
            foreach ($this->entries as $entry) {
                if ($entry['kind'] === $kind) {
                    $hits[$entry['seq']] = $entry;
                }
            }
        } else {
            $hits = $this->large[$kind];
            $cells = $this->cells[$kind];
            for ($x = $x0; $x <= $x1; $x++) {
                $base = $x * 1048576;
                for ($z = $z0; $z <= $z1; $z++) {
                    if (isset($cells[$base + $z])) {
                        foreach ($cells[$base + $z] as $seq => $entry) {
                            $hits[$seq] = $entry;
                        }
                    }
                }
            }
        }
        if (count($hits) > 1) {
            ksort($hits);
        }

        foreach ($hits as $entry) {
            $min = $entry['min'];
            $max = $entry['max'];
            if ($min->x <= $maxX && $max->x >= $minX && $min->z <= $maxZ && $max->z >= $minZ
                && !$entry['collider']->isTrigger
            ) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
