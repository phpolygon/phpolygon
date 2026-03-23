<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Camera2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextureManager;

class Renderer2DSystem extends AbstractSystem
{
    public function __construct(
        private readonly Renderer2DInterface $renderer,
        private readonly Camera2D $camera,
        private readonly TextureManager $textures,
    ) {}

    public function render(World $world): void
    {
        // Collect and sort by layer
        /** @var array<array{entity: Entity, sprite: SpriteRenderer, transform: Transform2D}> $renderables */
        $renderables = [];

        foreach ($world->query(SpriteRenderer::class, Transform2D::class) as $entity) {
            $sprite = $entity->get(SpriteRenderer::class);
            $transform = $entity->get(Transform2D::class);
            $renderables[] = [
                'entity' => $entity,
                'sprite' => $sprite,
                'transform' => $transform,
            ];
        }

        usort($renderables, fn($a, $b) => $a['sprite']->layer <=> $b['sprite']->layer);

        // Apply camera transform
        $viewMatrix = $this->camera->getViewMatrix();
        $this->renderer->pushTransform($viewMatrix);

        foreach ($renderables as $item) {
            $sprite = $item['sprite'];
            $transform = $item['transform'];

            if ($sprite->textureId === '') {
                continue;
            }

            $texture = $this->textures->get($sprite->textureId);
            if ($texture === null) {
                continue;
            }

            $w = $sprite->width > 0 ? (float)$sprite->width : (float)$texture->width;
            $h = $sprite->height > 0 ? (float)$sprite->height : (float)$texture->height;

            // Apply entity transform
            $localMatrix = $transform->getLocalMatrix();
            $this->renderer->pushTransform($localMatrix);

            // Handle flipping
            if ($sprite->flipX || $sprite->flipY) {
                $flipX = $sprite->flipX ? -1.0 : 1.0;
                $flipY = $sprite->flipY ? -1.0 : 1.0;
                $this->renderer->pushTransform(
                    \PHPolygon\Math\Mat3::scaling($flipX, $flipY)
                );
            }

            $this->renderer->drawSprite(
                $texture,
                $sprite->region,
                -$w * 0.5, // Center the sprite on its origin
                -$h * 0.5,
                $w,
                $h,
                $sprite->opacity * $sprite->color->a,
            );

            if ($sprite->flipX || $sprite->flipY) {
                $this->renderer->popTransform();
            }

            $this->renderer->popTransform();
        }

        $this->renderer->popTransform();
    }
}
