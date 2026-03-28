<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;

class Renderer3DSystem extends AbstractSystem
{
    public function __construct(
        private readonly Renderer3DInterface $renderer,
        private readonly RenderCommandList $commandList,
    ) {}

    public function render(World $world): void
    {
        // Collect lights in render() — must stay in sync with draws to avoid flickering
        foreach ($world->query(DirectionalLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(DirectionalLight::class);
            $this->commandList->add(new SetDirectionalLight(
                $light->direction,
                $light->color,
                $light->intensity,
            ));
        }

        foreach ($world->query(PointLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(PointLight::class);
            $transform = $entity->get(Transform3D::class);
            $this->commandList->add(new AddPointLight(
                $transform->getWorldPosition(),
                $light->color,
                $light->intensity,
                $light->radius,
            ));
        }

        // Collect mesh draw calls
        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            $transform = $entity->get(Transform3D::class);
            $this->commandList->add(new DrawMesh(
                $mesh->meshId,
                $mesh->materialId,
                $transform->getWorldMatrix(),
            ));
        }

        // Flush command list to renderer
        $this->renderer->render($this->commandList);

        // Clear for next frame
        $this->commandList->clear();
    }
}
