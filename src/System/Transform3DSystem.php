<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\TransformDirtyQueue;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\World;

/**
 * Computes world matrices for Transform3D components by traversing the
 * parent hierarchy. Must run before Camera3DSystem and Renderer3DSystem
 * each frame.
 *
 * Does NOT walk the whole Transform3D pool every tick. Transform3D's set
 * hooks (position/rotation/scale/parentEntityId) mark their entity in a
 * shared {@see TransformDirtyQueue} instead of this system discovering
 * changes by comparing ten floats per entity every tick - most of a built
 * world is thousands of roots that never move again after creation, and
 * that per-tick walk-and-compare was the dominant cost at a few thousand
 * entities (measured 2026-11-11: 3.3 -> 6.2 ms going from 5,600 to 11,800
 * mostly-static entities).
 *
 * Each tick, update() processes only:
 *  - ids drained from the dirty queue (a hooked assignment, {@see
 *    Transform3D::touch()}, or a just-attached/just-reparented entity -
 *    World::observe()'s attach callback marks a new Transform3D dirty so it
 *    gets its first worldMatrix without the system ever having walked for
 *    it);
 *  - ids caught by the safety net (see below);
 *  - ids woken from dormancy whose ancestor moved (or who moved themselves)
 *    while they were asleep ({@see $staleDormant}).
 * For each of those that is not itself reached through an ALREADY-dirty
 * ancestor this tick (the ancestor's own recursion will cover it), it calls
 * the unchanged {@see updateHierarchy()} with parentDirty=false - exactly
 * the call a static root used to get from the old full-pool loop. Whether
 * the matrix is actually rebuilt (and the matrix INSTANCE therefore
 * changes - Renderer3DSystem keys motion vectors off that identity, see its
 * $lastDrawnMatrix/prevModelMatrix comments) is still decided by isDirty()
 * or an ancestor's genuine move, same as before; being IN this tick's set
 * does not by itself force a rebuild, so a permanently-active entity that
 * happens not to have changed this tick still keeps its matrix instance.
 *
 * Safety net: Vec3/Quaternion are mutable, so `$t->position->x = …` never
 * runs a hook. Every tick, a 1/32 slice of the RESTING set (entities not
 * currently being processed) is checked the old brute-force way. An entity
 * caught this way is logged once (see logSafetyNetCatch()) and moved into
 * $permanentlyActive: it never rests again and is reprocessed (still gated
 * by isDirty(), so it stops costing a rebuild once it truly stops moving)
 * every tick from then on, trading its own cost for zero detection delay.
 * $permanentlyActive is expected to stay tiny - it only grows from code
 * that bypasses Transform3D::touch() after an in-place write.
 *
 * Dormant entities ({@see World::setEntityDormant()}) are not updated: a
 * dormant root is skipped at the top of update(), a dormant child is
 * skipped together with its subtree by updateHierarchy()'s recursion. A
 * parked subtree of thousands of parts under an awake parent therefore
 * costs nothing per tick. A dormant child whose ancestor moved while it was
 * skipped, OR that moved itself while dormant, is remembered in
 * $staleDormant and recomputed on its first awake tick, so its world matrix
 * is current again before anything renders it. While it sleeps, its world
 * matrix may be stale.
 */
class Transform3DSystem extends AbstractSystem
{
    /** Width of the safety-net sample: 1/N of the resting set is re-verified every tick. */
    private const SAFETY_NET_SAMPLE_DIVISOR = 32;

    /**
     * Snapshot of the last-known transform values per entity id. Used by
     * isDirty() - the real gate for whether a rebuild happens - and by the
     * safety net's brute-force comparison.
     *
     * @var array<int, array{px:float, py:float, pz:float, rx:float, ry:float, rz:float, rw:float, sx:float, sy:float, sz:float, parent:?int}>
     */
    private array $cache = [];

    /**
     * Entities to force-recompute on their next processed tick even though
     * their OWN fields did not change: a dormant child whose ancestor moved
     * while it was skipped, or a dormant entity that was itself written to
     * while asleep. Consumed (and unset) by updateHierarchy().
     *
     * @var array<int, true>
     */
    private array $staleDormant = [];

    /** Shared with every bound Transform3D; hooks mark() here instead of a per-tick pool walk. */
    private TransformDirtyQueue $dirtyQueue;

    /** The World this instance is currently observing; set once by bindToWorld(). */
    private ?World $boundWorld = null;

    /**
     * Packed sparse set of entity ids eligible for safety-net sampling (NOT
     * currently permanently active). $restingList is positionally indexed
     * for O(1) round-robin sampling; $restingPos is the reverse index so
     * removal is an O(1) swap-with-last, not a pool scan.
     *
     * Not typed as list<int>: the swap-with-last removal in removeResting()
     * assigns an existing index a new value rather than only ever pushing at
     * the end, which PHPStan cannot prove stays list-shaped even though it
     * always does (every index from 0 to count-1 is always occupied).
     *
     * @var array<int, int>
     */
    private array $restingList = [];

    /** @var array<int, int> entityId => index into $restingList */
    private array $restingPos = [];

    /** Round-robin read position into $restingList for the safety net. */
    private int $restingCursor = 0;

    /**
     * Entities the safety net has caught writing in place at least once.
     * Reprocessed (through the normal isDirty() gate) every tick from then
     * on instead of resting again - see the class docblock.
     *
     * @var array<int, true>
     */
    private array $permanentlyActive = [];

    /**
     * Entity ids whose world matrix was reassigned during the CURRENT/last
     * update() call. Rebuilt from scratch every tick.
     *
     * @var array<int, true>
     */
    private array $movedThisTick = [];

    /**
     * Same ids as $movedThisTick, but ACCUMULATED across update() calls
     * instead of reset every tick - drained only by {@see
     * drainMovedSinceLastDrain()}. GameLoop::runPipelined() can run more than
     * one fixed-timestep update() before the next render(); a consumer that
     * reads movedThisTick() once per rendered frame would lose every mover
     * from an earlier tick in that same frame. VisibilityService drains this
     * one instead, once per beginFrame().
     *
     * @var array<int, true>
     */
    private array $movedSinceDrain = [];

    public function __construct()
    {
        $this->dirtyQueue = new TransformDirtyQueue();
    }

    public function register(World $world): void
    {
        $this->bindToWorld($world);
    }

    public function update(World $world, float $dt): void
    {
        $this->bindToWorld($world);
        $this->movedThisTick = [];

        // Hook-driven changes since the last tick, plus anything a hook
        // can't see on its own.
        $dirty = $this->dirtyQueue->drain();

        // Caught before: always re-verified, isDirty() inside updateHierarchy()
        // still decides whether a rebuild actually happens.
        foreach ($this->permanentlyActive as $id => $_) {
            $dirty[$id] = true;
        }

        // 1/32 of the resting set, brute-force compared - catches in-place
        // Vec3/Quaternion writes the hooks above never saw.
        foreach ($this->sampleSafetyNet($world, $dirty) as $id) {
            $dirty[$id] = true;
        }

        // Dormant entities that woke up since they were marked stale (their
        // own change, or an ancestor's, while asleep) - the only other way
        // an entity needs attention without a hook firing on it this tick.
        foreach ($this->staleDormant as $id => $_) {
            if ($world->isAlive($id) && !$world->isDormant($id)) {
                $dirty[$id] = true;
            }
        }

        foreach ($dirty as $id => $_) {
            if (!$world->isAlive($id)) {
                continue;
            }
            /** @var Transform3D|null $transform */
            $transform = $world->tryGetComponent($id, Transform3D::class);
            if (!$transform instanceof Transform3D) {
                continue;
            }
            if ($this->hasDirtyAncestor($world, $transform, $dirty)) {
                // An ancestor in this same set will reach it through its own
                // recursion (which forces the whole moved subtree, dormant
                // children aside) - processing it here too would just repeat
                // that work.
                continue;
            }
            if ($world->isDormant($id)) {
                // Mirrors componentPool() excluding dormant entities from the
                // old full-pool walk: a dormant top-level entry is not
                // computed, but remembered so it catches up on wake.
                $this->staleDormant[$id] = true;
                continue;
            }
            $this->updateHierarchy($world, $id, $transform, parentDirty: false);
        }
    }

    /**
     * Entity ids whose world matrix was reassigned during the last update()
     * call - movers, freshly attached/woken entities, safety-net catches.
     * For Phase 2's spatial index to update incrementally instead of
     * re-deriving visibility from scratch every frame.
     *
     * @return list<int>
     */
    public function movedThisTick(): array
    {
        return array_keys($this->movedThisTick);
    }

    /**
     * Entity ids moved since the last call to this method - see
     * $movedSinceDrain for why this differs from movedThisTick() when more
     * than one update() runs before the next drain.
     *
     * @return list<int>
     */
    public function drainMovedSinceLastDrain(): array
    {
        $ids = array_keys($this->movedSinceDrain);
        $this->movedSinceDrain = [];
        return $ids;
    }

    /**
     * Drop the per-entity snapshot and every bookkeeping set when
     * World::clear() runs. After clear() the id counter restarts from 0, so
     * the next createEntity() hands out the same ids; without resetting
     * $cache, isDirty() compares fresh transforms against the snapshot of
     * the previous occupant of that id and skips the worldMatrix recompute
     * when floats happen to match — children would render at LOCAL
     * coordinates for one frame after a restart. The other sets would
     * otherwise keep pointing at ids clear() just destroyed (their detach
     * notification already cleans them up incrementally, but a wholesale
     * reset here does not depend on that ordering).
     */
    public function onWorldClear(World $world): void
    {
        $this->cache = [];
        $this->staleDormant = [];
        $this->restingList = [];
        $this->restingPos = [];
        $this->restingCursor = 0;
        $this->permanentlyActive = [];
        $this->movedThisTick = [];
        $this->movedSinceDrain = [];
        $this->dirtyQueue->clear();
    }

    /**
     * Starts observing $world's Transform3D attach/detach the first time
     * this system sees it - from register() if addSystem() was used, or
     * lazily from the first update() otherwise (several tests and
     * benchmarks construct a Transform3DSystem standalone and call update()
     * directly). Binds every ALREADY-attached Transform3D too (bootstrap):
     * attach notifications only fire for components attached from here on.
     *
     * Walks aliveEntityIds() rather than componentPool() for the bootstrap,
     * so an entity that is already dormant at this point is still bound -
     * its hooks must keep queuing it so it is current on its first awake
     * tick, even though it was never a "most of a built world" cost case.
     */
    private function bindToWorld(World $world): void
    {
        if ($world === $this->boundWorld) {
            return;
        }
        $this->boundWorld = $world;

        $queue = $this->dirtyQueue;
        $world->observe(
            Transform3D::class,
            static function (int $id, ComponentInterface $component) use ($queue): void {
                /** @var Transform3D $component */
                $component->bindDirtyQueue($id, $queue);
                $queue->mark($id);
            },
            function (int $id, ComponentInterface $component): void {
                /** @var Transform3D $component */
                $component->unbindDirtyQueue();
                $this->forget($id);
            },
        );

        foreach ($world->aliveEntityIds() as $id) {
            $transform = $world->tryGetComponent($id, Transform3D::class);
            if ($transform instanceof Transform3D) {
                $transform->bindDirtyQueue($id, $queue);
                $queue->mark($id);
            }
        }
    }

    /** Drop every bookkeeping entry for an entity id whose Transform3D detached. */
    private function forget(int $id): void
    {
        $this->removeResting($id);
        unset(
            $this->permanentlyActive[$id],
            $this->staleDormant[$id],
            $this->cache[$id],
            $this->movedSinceDrain[$id],
        );
    }

    private function makeResting(int $id): void
    {
        if (isset($this->permanentlyActive[$id]) || isset($this->restingPos[$id])) {
            return;
        }
        $idx = count($this->restingList);
        $this->restingList[$idx] = $id;
        $this->restingPos[$id] = $idx;
    }

    /** O(1) swap-with-last removal - no pool scan. */
    private function removeResting(int $id): void
    {
        if (!isset($this->restingPos[$id])) {
            return;
        }
        $idx = $this->restingPos[$id];
        $lastIdx = count($this->restingList) - 1;
        $lastId = $this->restingList[$lastIdx];
        $this->restingList[$idx] = $lastId;
        $this->restingPos[$lastId] = $idx;
        array_pop($this->restingList);
        unset($this->restingPos[$id]);
        if ($this->restingCursor > $lastIdx) {
            $this->restingCursor = 0;
        }
    }

    /**
     * Brute-force compares a round-robin slice (1/{@see SAFETY_NET_SAMPLE_DIVISOR})
     * of the resting set against its cached snapshot, catching in-place
     * Vec3/Quaternion writes that never ran a hook. Returns the ids actually
     * found changed (moved into $permanentlyActive and logged here); ids
     * already in $alreadyDirty are skipped (they're reprocessed this tick
     * anyway, comparing them against a not-yet-updated snapshot would read
     * as "changed" even though the hook already caught them).
     *
     * @param array<int, true> $alreadyDirty
     * @return list<int>
     */
    private function sampleSafetyNet(World $world, array $alreadyDirty): array
    {
        $count = count($this->restingList);
        if ($count === 0) {
            return [];
        }
        $budget = (int) max(1, (int) ceil($count / self::SAFETY_NET_SAMPLE_DIVISOR));
        $caught = [];
        for ($i = 0; $i < $budget; $i++) {
            $size = count($this->restingList);
            if ($size === 0) {
                break;
            }
            if ($this->restingCursor >= $size) {
                $this->restingCursor = 0;
            }
            $id = $this->restingList[$this->restingCursor];
            $this->restingCursor++;

            if (isset($alreadyDirty[$id])) {
                continue;
            }
            /** @var Transform3D|null $transform */
            $transform = $world->tryGetComponent($id, Transform3D::class);
            if (!$transform instanceof Transform3D || !$this->isDirty($id, $transform)) {
                continue;
            }

            $this->removeResting($id);
            $this->permanentlyActive[$id] = true;
            $this->logSafetyNetCatch($world, $id);
            $caught[] = $id;
        }
        return $caught;
    }

    /**
     * One-shot diagnostic for a safety-net catch (PHPOLYGON_DEV_WARNINGS=0
     * silences it). Follows the engine's existing getenv()-gated dev output
     * convention (see e.g. VioRenderer3D's PHPOLYGON_* flags) rather than a
     * new logging facade.
     */
    private function logSafetyNetCatch(World $world, int $id): void
    {
        if (getenv('PHPOLYGON_DEV_WARNINGS') === '0') {
            return;
        }
        $nameTag = $world->tryGetComponent($id, NameTag::class);
        $label = $nameTag instanceof NameTag && $nameTag->name !== '' ? $nameTag->name : "entity #{$id}";
        error_log(sprintf(
            "[Transform3DSystem] %s moved its Transform3D in place (a mutated Vec3/Quaternion field) "
            . 'instead of assigning position/rotation/scale or calling touch(); caught by the safety net '
            . 'up to %d ticks late and now updated every tick instead of resting. Call '
            . '$transform->touch() right after the in-place write to avoid the delay.',
            $label,
            self::SAFETY_NET_SAMPLE_DIVISOR,
        ));
    }

    /**
     * @param array<int, true> $dirty
     */
    private function hasDirtyAncestor(World $world, Transform3D $transform, array $dirty): bool
    {
        $parentId = $transform->parentEntityId;
        while ($parentId !== null) {
            if (isset($dirty[$parentId])) {
                return true;
            }
            $parentTransform = $world->tryGetComponent($parentId, Transform3D::class);
            if (!$parentTransform instanceof Transform3D) {
                return false;
            }
            $parentId = $parentTransform->parentEntityId;
        }
        return false;
    }

    private function updateHierarchy(World $world, int $entityId, Transform3D $transform, bool $parentDirty): void
    {
        if (isset($this->staleDormant[$entityId])) {
            // An ancestor moved while this entity was dormant and skipped,
            // or it moved itself while dormant - either way its own fields
            // may not have changed, so isDirty() alone would miss it.
            unset($this->staleDormant[$entityId]);
            $parentDirty = true;
        }
        $dirty = $parentDirty || $this->isDirty($entityId, $transform);

        if ($dirty) {
            if ($transform->parentEntityId === null) {
                $transform->worldMatrix = $transform->getLocalMatrix();
            } else {
                $parentTransform = $world->tryGetComponent($transform->parentEntityId, Transform3D::class);
                if ($parentTransform instanceof Transform3D) {
                    $transform->worldMatrix = $parentTransform->worldMatrix->multiply($transform->getLocalMatrix());
                } else {
                    $transform->worldMatrix = $transform->getLocalMatrix();
                }
            }
            $this->snapshot($entityId, $transform);
            $this->movedThisTick[$entityId] = true;
            $this->movedSinceDrain[$entityId] = true;
            $this->makeResting($entityId);
        }

        if ($transform->childEntityIds === []) {
            return;
        }

        foreach ($transform->childEntityIds as $childId) {
            if (!$world->isAlive($childId)) {
                continue;
            }
            // A dormant child is skipped with its subtree, like a dormant root.
            // If this entity moved meanwhile, the child is recomputed on its
            // first awake tick.
            if ($world->isDormant($childId)) {
                if ($dirty) {
                    $this->staleDormant[$childId] = true;
                }
                continue;
            }
            $childTransform = $world->tryGetComponent($childId, Transform3D::class);
            if (!$childTransform instanceof Transform3D) {
                continue;
            }
            $this->updateHierarchy($world, $childId, $childTransform, parentDirty: $dirty);
        }
    }

    private function isDirty(int $entityId, Transform3D $t): bool
    {
        $snap = $this->cache[$entityId] ?? null;
        if ($snap === null) {
            return true;
        }
        $p = $t->position;
        $r = $t->rotation;
        $s = $t->scale;
        // Strict equality on floats: any animation system writing to the
        // transform will have produced new float values, so === is enough
        // to detect change without epsilon comparisons.
        return $snap['px'] !== $p->x
            || $snap['py'] !== $p->y
            || $snap['pz'] !== $p->z
            || $snap['rx'] !== $r->x
            || $snap['ry'] !== $r->y
            || $snap['rz'] !== $r->z
            || $snap['rw'] !== $r->w
            || $snap['sx'] !== $s->x
            || $snap['sy'] !== $s->y
            || $snap['sz'] !== $s->z
            || $snap['parent'] !== $t->parentEntityId;
    }

    private function snapshot(int $entityId, Transform3D $t): void
    {
        $this->cache[$entityId] = [
            'px' => $t->position->x,
            'py' => $t->position->y,
            'pz' => $t->position->z,
            'rx' => $t->rotation->x,
            'ry' => $t->rotation->y,
            'rz' => $t->rotation->z,
            'rw' => $t->rotation->w,
            'sx' => $t->scale->x,
            'sy' => $t->scale->y,
            'sz' => $t->scale->z,
            'parent' => $t->parentEntityId,
        ];
    }
}
