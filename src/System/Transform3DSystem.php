<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Computes world matrices for Transform3D components by traversing the parent hierarchy.
 * Must run before Camera3DSystem and Renderer3DSystem each frame.
 */
class Transform3DSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        // First pass: update all root entities (no parent)
        foreach ($world->query(Transform3D::class) as $entity) {
            $transform = $entity->get(Transform3D::class);
            if ($transform->parentEntityId !== null) {
                continue;
            }
            $this->updateHierarchy($world, $transform);
        }
    }

    private function updateHierarchy(World $world, Transform3D $transform): void
    {
        // Root: world = local
        if ($transform->parentEntityId === null) {
            $transform->worldMatrix = $transform->getLocalMatrix();
        }

        // Recurse into children
        foreach ($transform->childEntityIds as $childId) {
            if (!$world->isAlive($childId)) {
                continue;
            }
            $childTransform = $world->tryGetComponent($childId, Transform3D::class);
            if (!$childTransform instanceof Transform3D) {
                continue;
            }
            // child world = parent world * child local
            $childTransform->worldMatrix = $transform->worldMatrix->multiply($childTransform->getLocalMatrix());
            $this->updateHierarchy($world, $childTransform);
        }
    }
}
