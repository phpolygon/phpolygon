<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Transform3D;
use PHPolygon\Component\WorldText;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Command\DrawWorldText;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

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
        $cameraPos = $this->extractCameraPosition($camera->viewMatrix);

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
            $screen = $this->worldToScreen($worldPos, $vpMatrix);
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

    private function extractCameraPosition(Mat4 $viewMatrix): Vec3
    {
        $inv = $viewMatrix->inverse();
        return new Vec3($inv->get(0, 3), $inv->get(1, 3), $inv->get(2, 3));
    }

    /**
     * Project a world position to screen pixel coordinates.
     *
     * @return Vec3|null Screen position (x, y in pixels), or null if behind camera.
     */
    private function worldToScreen(Vec3 $worldPos, Mat4 $vpMatrix): ?Vec3
    {
        $clip = $vpMatrix->multiplyVec4(new Vec4($worldPos->x, $worldPos->y, $worldPos->z, 1.0));

        // Behind the camera
        if ($clip->w <= 0.0) {
            return null;
        }

        $ndcX = $clip->x / $clip->w;
        $ndcY = $clip->y / $clip->w;

        // Clip to visible range
        if ($ndcX < -1.0 || $ndcX > 1.0 || $ndcY < -1.0 || $ndcY > 1.0) {
            return null;
        }

        $screenX = ($ndcX + 1.0) * 0.5 * $this->viewportWidth;
        $screenY = (1.0 - $ndcY) * 0.5 * $this->viewportHeight;

        return new Vec3($screenX, $screenY, 0.0);
    }
}
