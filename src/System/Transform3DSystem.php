<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Computes world matrices for Transform3D components by traversing the
 * parent hierarchy. Must run before Camera3DSystem and Renderer3DSystem
 * each frame.
 *
 * Caches the last-known position / rotation / scale per entity and only
 * rebuilds the world matrix via {@see \PHPolygon\Math\Mat4::trs()} when at
 * least one component changed (or an ancestor moved). Most scenes contain
 * thousands of static entities (terrain props, buildings, decoration), and
 * the trig inside {@see \PHPolygon\Math\Quaternion::toRotationMatrix()}
 * called for every transform every frame is the dominant CPU cost in PHP.
 *
 * Dormant entities ({@see World::setEntityDormant()}) are not updated: a
 * dormant root is not in the pool, and a dormant child is skipped together
 * with its subtree. A parked subtree of thousands of parts under an awake
 * parent therefore costs nothing per tick. A dormant child whose ancestor moved
 * while it was skipped is remembered and recomputed on its first awake tick,
 * so its world matrix is current again before anything renders it. While it
 * sleeps, its world matrix may be stale.
 */
class Transform3DSystem extends AbstractSystem
{
    /**
     * Snapshot of the last-known transform values per entity id.
     *
     * @var array<int, array{px:float, py:float, pz:float, rx:float, ry:float, rz:float, rw:float, sx:float, sy:float, sz:float, parent:?int}>
     */
    private array $cache = [];

    /**
     * Dormant children whose ancestor moved while they were skipped; they are
     * recomputed on their first awake tick.
     *
     * @var array<int, true>
     */
    private array $staleDormant = [];

    public function update(World $world, float $dt): void
    {
        // First pass: process root entities (no parent). updateHierarchy
        // recurses into children so each entity is touched exactly once.
        //
        // Direct pool iteration (not query()): the island has thousands of
        // Transform3D entities and this runs every fixed-timestep tick, so the
        // per-entity generator resume + Entity wrap + get() indirection was a
        // measurable steady-state cost. componentPool() hands back the raw
        // [id => Transform3D] map; behaviour is identical (same roots, same
        // recursion).
        /** @var array<int, Transform3D> $pool */
        $pool = $world->componentPool(Transform3D::class);
        $cache = &$this->cache;
        foreach ($pool as $id => $transform) {
            if ($transform->parentEntityId !== null) {
                continue;
            }
            if ($transform->childEntityIds !== []) {
                $this->updateHierarchy($world, $id, $transform, parentDirty: false);
                continue;
            }

            // Childless root - most of a built world. Same check as isDirty(),
            // inlined: two method calls per entity per tick are a measurable
            // share of the steady-state cost at a few thousand entities.
            $p = $transform->position;
            $r = $transform->rotation;
            $s = $transform->scale;
            $snap = $cache[$id] ?? null;
            if ($snap !== null
                && $snap['px'] === $p->x && $snap['py'] === $p->y && $snap['pz'] === $p->z
                && $snap['rx'] === $r->x && $snap['ry'] === $r->y && $snap['rz'] === $r->z && $snap['rw'] === $r->w
                && $snap['sx'] === $s->x && $snap['sy'] === $s->y && $snap['sz'] === $s->z
                && $snap['parent'] === null
            ) {
                continue;
            }
            $transform->worldMatrix = $transform->getLocalMatrix();
            $this->snapshot($id, $transform);
        }
        unset($cache);
    }

    /**
     * Drop the per-entity snapshot when World::clear() runs. After clear() the
     * id counter restarts from 0, so the next createEntity() hands out the same
     * ids; without resetting $cache, isDirty() compares fresh transforms
     * against the snapshot of the previous occupant of that id and skips the
     * worldMatrix recompute when floats happen to match — children would
     * render at LOCAL coordinates for one frame after a restart.
     */
    public function onWorldClear(World $world): void
    {
        $this->cache = [];
        $this->staleDormant = [];
    }

    private function updateHierarchy(World $world, int $entityId, Transform3D $transform, bool $parentDirty): void
    {
        if (isset($this->staleDormant[$entityId])) {
            // An ancestor moved while this entity was dormant and skipped.
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
