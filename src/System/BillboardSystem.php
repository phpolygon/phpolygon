<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Billboard;
use PHPolygon\Component\BillboardMode;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Rotates Billboard entities to face the active camera and emits
 * draw commands with the billboard-oriented model matrix.
 *
 * Must be registered after the camera system so SetCamera is available.
 */
class BillboardSystem extends AbstractSystem
{
    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    public function render(World $world): void
    {
        // Extract camera position from the last SetCamera command's view matrix
        $cameraPos = $this->extractCameraPosition();
        if ($cameraPos === null) {
            return;
        }

        foreach ($world->query(Billboard::class, MeshRenderer::class, Transform3D::class) as $entity) {
            $billboard = $entity->get(Billboard::class);
            $mesh = $entity->get(MeshRenderer::class);
            $transform = $entity->get(Transform3D::class);

            $pos = $transform->getWorldPosition();
            $rotation = $this->computeBillboardRotation($pos, $cameraPos, $billboard->mode);

            // Build model matrix with billboard rotation
            $modelMatrix = Mat4::trs($pos, $rotation, $transform->scale);

            $this->commandList->add(new DrawMesh(
                $mesh->meshId,
                $mesh->materialId,
                $modelMatrix,
            ));
        }
    }

    private function extractCameraPosition(): ?Vec3
    {
        $cameras = $this->commandList->ofType(SetCamera::class);
        if ($cameras === []) {
            return null;
        }

        // Camera position = inverse of view matrix translation
        $viewMatrix = $cameras[0]->viewMatrix;
        $inv = $viewMatrix->inverse();
        return new Vec3($inv->get(0, 3), $inv->get(1, 3), $inv->get(2, 3));
    }

    private function computeBillboardRotation(Vec3 $entityPos, Vec3 $cameraPos, BillboardMode $mode): Quaternion
    {
        $toCamera = $cameraPos->sub($entityPos);

        if ($mode === BillboardMode::AxisY) {
            // Only rotate around Y axis
            $yaw = atan2($toCamera->x, $toCamera->z);
            return Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $yaw);
        }

        // Full billboard: Y rotation + X pitch
        $lenXZ = sqrt($toCamera->x * $toCamera->x + $toCamera->z * $toCamera->z);
        $yaw = atan2($toCamera->x, $toCamera->z);
        $pitch = -atan2($toCamera->y, $lenXZ);

        $qYaw = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $yaw);
        $qPitch = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $pitch);

        return $qYaw->multiply($qPitch);
    }
}
