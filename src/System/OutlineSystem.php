<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Outline;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\DrawOutline;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Emits {@see DrawOutline} commands for every enabled {@see Outline}: the
 * entity's own visible MeshRenderer plus, unless disabled, those of all
 * Transform3D descendants.
 *
 * Register it BEFORE {@see Renderer3DSystem}: the renderer consumes the command
 * list in that system's render(), so outlines added afterwards would wait a
 * frame.
 */
class OutlineSystem extends AbstractSystem
{
    /** Guard against cyclic or absurdly deep hierarchies. */
    private const int MAX_DEPTH = 32;

    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    public function render(World $world): void
    {
        /** @var array<int, Outline> $pool */
        $pool = $world->componentPool(Outline::class);
        foreach ($pool as $entityId => $outline) {
            if ($outline->enabled) {
                $this->emit($world, $entityId, $outline, 0);
            }
        }
    }

    private function emit(World $world, int $entityId, Outline $outline, int $depth): void
    {
        $transform = $world->tryGetComponent($entityId, Transform3D::class);
        if (!$transform instanceof Transform3D) {
            return;
        }

        $mesh = $world->tryGetComponent($entityId, MeshRenderer::class);
        if ($mesh instanceof MeshRenderer && $mesh->visible && $mesh->meshId !== '') {
            $this->commandList->add(new DrawOutline(
                $mesh->meshId,
                $transform->getWorldMatrix(),
                $outline->color,
                $outline->widthPx,
            ));
        }

        if (!$outline->includeChildren || $depth >= self::MAX_DEPTH) {
            return;
        }
        foreach ($transform->childEntityIds as $childId) {
            if ($world->isAlive($childId)) {
                $this->emit($world, $childId, $outline, $depth + 1);
            }
        }
    }
}
