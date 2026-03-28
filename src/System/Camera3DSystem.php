<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\ProjectionType;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

class Camera3DSystem extends AbstractSystem
{
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly int $viewportWidth,
        private readonly int $viewportHeight,
    ) {}

    public function render(World $world): void
    {
        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $cam = $entity->get(Camera3DComponent::class);
            if (!$cam->active) {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $worldPos = $transform->getWorldPosition();

            $forward = $transform->rotation->rotateVec3(new Vec3(0.0, 0.0, -1.0));
            $up      = $transform->rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0));

            $viewMatrix = Mat4::lookAt($worldPos, $worldPos->add($forward), $up);

            $aspect = $this->viewportHeight > 0
                ? (float)$this->viewportWidth / (float)$this->viewportHeight
                : 1.0;

            $projectionMatrix = match ($cam->projectionType) {
                ProjectionType::Perspective  => Mat4::perspective(deg2rad($cam->fov), $aspect, $cam->near, $cam->far),
                ProjectionType::Orthographic => Mat4::orthographic(
                    -$aspect * $cam->far * 0.5,
                     $aspect * $cam->far * 0.5,
                    -$cam->far * 0.5,
                     $cam->far * 0.5,
                     $cam->near,
                     $cam->far,
                ),
            };

            $this->commandList->add(new SetCamera($viewMatrix, $projectionMatrix));
            break; // Only one active camera per frame
        }
    }
}
