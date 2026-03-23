<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera2DComponent;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Camera2D;

class Camera2DSystem extends AbstractSystem
{
    public function __construct(
        private readonly Camera2D $camera,
    ) {}

    public function update(World $world, float $dt): void
    {
        foreach ($world->query(Camera2DComponent::class, Transform2D::class) as $entity) {
            $cam = $entity->get(Camera2DComponent::class);
            if (!$cam->active) {
                continue;
            }

            $transform = $entity->get(Transform2D::class);
            $this->camera->position = $transform->position;
            $this->camera->zoom = $cam->zoom;
            break; // Only one active camera
        }
    }
}
