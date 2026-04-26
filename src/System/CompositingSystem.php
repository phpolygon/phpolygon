<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SceneRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\ClearDepthBuffer;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\RenderLayer;

/**
 * Orchestrates multi-layer rendering by collecting SceneRenderer
 * components and emitting draw commands grouped by RenderLayer.
 *
 * Entities without a SceneRenderer default to World3D. Layers are
 * rendered in enum value order. A ClearDepthBuffer command is
 * inserted before a layer if any entity in that layer requests it.
 *
 * This system should be registered AFTER Camera3DSystem/IsometricCameraSystem
 * (which emit SetCamera) and BEFORE Renderer3DSystem flushes the command list.
 */
class CompositingSystem extends AbstractSystem
{
    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    public function render(World $world): void
    {
        // Collect entities that have both SceneRenderer and mesh rendering
        /** @var array<int, list<array{entity: Entity, sortOrder: int, clearDepth: bool}>> */
        $layers = [];

        foreach ($world->query(SceneRenderer::class, MeshRenderer::class, Transform3D::class) as $entity) {
            $sceneRenderer = $entity->get(SceneRenderer::class);
            if (!$sceneRenderer->enabled) {
                continue;
            }

            $layerValue = $sceneRenderer->renderLayer->value;
            $layers[$layerValue][] = [
                'entity' => $entity,
                'sortOrder' => $sceneRenderer->sortOrder,
                'clearDepth' => $sceneRenderer->clearDepth,
            ];
        }

        if ($layers === []) {
            return;
        }

        // Sort layer keys (render in layer order)
        ksort($layers);

        foreach ($layers as $entries) {
            // Sort within layer by sortOrder
            usort($entries, static fn(array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

            // Insert ClearDepthBuffer if any entity in this layer requests it
            foreach ($entries as $entry) {
                if ($entry['clearDepth']) {
                    $this->commandList->add(new ClearDepthBuffer());
                    break;
                }
            }

            // Emit draw commands for this layer
            foreach ($entries as $entry) {
                /** @var Entity $entity */
                $entity = $entry['entity'];
                $mesh = $entity->get(MeshRenderer::class);
                $transform = $entity->get(Transform3D::class);

                $this->commandList->add(new DrawMesh(
                    $mesh->meshId,
                    $mesh->materialId,
                    $transform->getLocalMatrix(),
                ));
            }
        }
    }
}
