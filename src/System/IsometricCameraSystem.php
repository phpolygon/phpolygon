<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\IsometricCamera;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Builds an orthographic view/projection from an IsometricCamera component.
 *
 * The camera orbits the entity's Transform3D position using spherical
 * coordinates (angle, pitch, distance). An orthographic projection
 * is sized by the zoom parameter.
 */
class IsometricCameraSystem extends AbstractSystem
{
    private ?Vec3 $smoothedTarget = null;

    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly int $viewportWidth,
        private readonly int $viewportHeight,
    ) {}

    public function render(World $world): void
    {
        foreach ($world->query(IsometricCamera::class, Transform3D::class) as $entity) {
            $cam = $entity->get(IsometricCamera::class);
            if (!$cam->active) {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $target = $transform->getWorldPosition();

            // Smooth follow
            if ($cam->smoothing > 0.0 && $this->smoothedTarget !== null) {
                $target = $this->smoothedTarget->lerp($target, 1.0 - $cam->smoothing);
            }
            $this->smoothedTarget = $target;

            // Spherical offset: angle = Y rotation, pitch = elevation
            $angleRad = deg2rad($cam->angle);
            $pitchRad = deg2rad($cam->pitch);

            $cosPitch = cos($pitchRad);
            $offset = new Vec3(
                $cam->distance * $cosPitch * sin($angleRad),
                $cam->distance * sin($pitchRad),
                $cam->distance * $cosPitch * cos($angleRad),
            );

            $eye = $target->add($offset);
            $viewMatrix = Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0));

            // Orthographic projection sized by zoom
            $aspect = $this->viewportHeight > 0
                ? (float) $this->viewportWidth / (float) $this->viewportHeight
                : 1.0;

            $halfH = $cam->zoom;
            $halfW = $halfH * $aspect;

            $projectionMatrix = Mat4::orthographic(
                -$halfW, $halfW,
                -$halfH, $halfH,
                $cam->near, $cam->far,
            );

            $this->commandList->add(new SetCamera($viewMatrix, $projectionMatrix));
            break; // Only one active camera per frame
        }
    }
}
