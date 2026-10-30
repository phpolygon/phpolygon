<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\Component\WorldText;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\DrawWorldText;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ScreenProjection;

/**
 * Projects WorldText entities to screen space and emits DrawWorldText
 * commands for the 2D renderer.
 *
 * Must be registered after the camera system so SetCamera is available.
 */
class WorldTextSystem extends AbstractSystem
{
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly int $viewportWidth,
        private readonly int $viewportHeight,
    ) {}

    public function render(World $world): void
    {
        $cameras = $this->commandList->ofType(SetCamera::class);
        if ($cameras === []) {
            return;
        }

        $camera = $cameras[0];
        $vpMatrix = $camera->projectionMatrix->multiply($camera->viewMatrix);
        $cameraPos = ScreenProjection::cameraPosition($camera->viewMatrix);

        foreach ($world->query(WorldText::class, Transform3D::class) as $entity) {
            $text = $entity->get(WorldText::class);
            if ($text->text === '') {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $worldPos = $transform->getWorldPosition();

            // Distance culling
            if ($text->maxDistance > 0.0) {
                $dist = $worldPos->distanceSquaredTo($cameraPos);
                if ($dist > $text->maxDistance * $text->maxDistance) {
                    continue;
                }
            }

            // Project to screen space
            $screen = ScreenProjection::worldToScreen($worldPos, $vpMatrix, $this->viewportWidth, $this->viewportHeight);
            if ($screen === null) {
                continue;
            }

            // Scale font size by distance if enabled
            $fontSize = $text->fontSize;
            if ($text->scaleWithDistance) {
                $dist = sqrt($worldPos->distanceSquaredTo($cameraPos));
                if ($dist > 1.0) {
                    // Reference distance = 10 units
                    $fontSize *= 10.0 / $dist;
                }
                $fontSize = max(4.0, $fontSize);
            }

            $this->commandList->add(new DrawWorldText(
                text: $text->text,
                screenX: $screen->x,
                screenY: $screen->y + $text->screenOffsetY,
                fontSize: $fontSize,
                color: $text->color,
                fontId: $text->fontId,
                textAlign: $text->textAlign,
            ));
        }
    }
}
