<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\PlanarMirror;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetPlanarMirror;
use PHPolygon\Rendering\Reflection\MirrorSelector;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Visibility\VisibilityService;

/**
 * Picks the planar mirror of the frame among the {@see PlanarMirror} components
 * ({@see MirrorSelector}) and queues one {@see SetPlanarMirror}. Register it
 * after Camera3DSystem and before Renderer3DSystem: the camera must be in the list,
 * and Renderer3DSystem reads the mirror to add the draws only the mirror sees.
 * Without a mirror component, or in range of none, it queues nothing.
 */
class PlanarMirrorSystem extends AbstractSystem
{
    /** Number of mirror components that qualified last frame (for tests and the profile). */
    private int $qualified = 0;

    public function __construct(private readonly RenderCommandList $commandList) {}

    public function render(World $world): void
    {
        $camera = $this->commandList->lastOfType(SetCamera::class);
        if (!$camera instanceof SetCamera) {
            return;
        }
        $candidates = [];
        /** @var array<int, PlanarMirror> $mirrors */
        $mirrors = $world->componentPool(PlanarMirror::class);
        if ($mirrors === []) {
            return;
        }
        /** @var array<int, Transform3D> $transforms */
        $transforms = $world->componentPool(Transform3D::class);
        foreach ($mirrors as $entityId => $mirror) {
            $transform = $transforms[$entityId] ?? null;
            if (!$mirror->enabled || $transform === null || $world->isDormant($entityId)) {
                continue;
            }
            $m = $transform->worldMatrix;
            $center = $m->getTranslation();
            $right = $m->transformDirection(new Vec3(1.0, 0.0, 0.0));
            $up = $m->transformDirection(new Vec3(0.0, 1.0, 0.0));
            $normal = $m->transformDirection(new Vec3(0.0, 0.0, 1.0));
            // World scale of the entity multiplies the rectangle.
            $candidates[] = new SetPlanarMirror(
                $center,
                $normal,
                $right,
                0.5 * $mirror->width * $right->length(),
                0.5 * $mirror->height * $up->length(),
                $mirror->resolutionScale,
                $mirror->maxDistance,
                $mirror->updateEvery,
                $mirror->layerMask,
                $mirror->blur,
                'e' . $entityId,
            );
        }
        $eye = $camera->viewMatrix->inverse()->getTranslation();
        $visibility = VisibilityService::forWorld($world);
        $visibility->beginFrameFrom($world, $this->commandList);
        $planes = $visibility->frame()?->planes;
        $chosen = MirrorSelector::choose($candidates, $eye, $planes);
        $this->qualified = $chosen === null ? 0 : 1;
        if ($chosen !== null) {
            $this->commandList->add($chosen);
        }
    }

    /** 1 when a mirror was queued last frame, else 0. */
    public function qualifiedLastFrame(): int
    {
        return $this->qualified;
    }
}
