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
 */
class Transform3DSystem extends AbstractSystem
{
    /**
     * Snapshot of the last-known transform values per entity id.
     *
     * @var array<int, array{px:float, py:float, pz:float, rx:float, ry:float, rz:float, rw:float, sx:float, sy:float, sz:float, parent:?int}>
     */
    private array $cache = [];

    public function update(World $world, float $dt): void
    {
        // First pass: process root entities (no parent). updateHierarchy
        // recurses into children so each entity is touched exactly once.
        foreach ($world->query(Transform3D::class) as $entity) {
            $transform = $entity->get(Transform3D::class);
            if ($transform->parentEntityId !== null) {
                continue;
            }
            $this->updateHierarchy($world, $entity->id, $transform, parentDirty: false);
        }
    }

    private function updateHierarchy(World $world, int $entityId, Transform3D $transform, bool $parentDirty): void
    {
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
