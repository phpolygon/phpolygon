<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\ProjectionType;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Runtime\Window;

class Camera3DSystem extends AbstractSystem
{
    /**
     * A per-tick jump larger than this (squared, world units) is treated as a
     * teleport/respawn and snaps instead of interpolating — otherwise the view
     * would smear across the discontinuity for one render frame.
     */
    private const SNAP_DIST_SQ = 25.0; // 5 units in one 1/60 s tick

    private readonly bool $interpolate;

    /**
     * Fixed-timestep interpolation history, keyed by camera entity id:
     * previous + current tick-end position/rotation. update() double-buffers it
     * at the end of every sim tick; render() lerps/slerps between the two by the
     * engine's render-interpolation factor so the camera (and the bright sun it
     * frames) stays smooth when render fps ≫ update Hz.
     *
     * @var array<int, array{ppos: Vec3, prot: Quaternion, cpos: Vec3, crot: Quaternion}>
     */
    private array $interp = [];

    public function __construct(
        private readonly RenderCommandList $commandList,
        private int $viewportWidth,
        private int $viewportHeight,
        private readonly ?Window $window = null,
        private readonly ?Engine $engine = null,
    ) {
        // On by default; PHPOLYGON_NO_CAMERA_INTERP=1 falls back to the raw
        // (snap-to-tick) camera if interpolation ever feels off.
        $this->interpolate = $engine !== null
            && getenv('PHPOLYGON_NO_CAMERA_INTERP') !== '1';
    }

    public function setViewport(int $width, int $height): void
    {
        $this->viewportWidth = $width;
        $this->viewportHeight = $height;
    }

    /**
     * Drop interpolation history on World::clear() — after clear the entity ids
     * restart from 0, so a stale entry would interpolate the new camera from the
     * previous scene's camera pose for one frame.
     */
    public function onWorldClear(World $world): void
    {
        $this->interp = [];
    }

    /**
     * Record the active camera's tick-end transform. Runs once per fixed sim
     * tick; registered after the camera movers (input, physics, cutscene), so
     * the captured state already includes everything that moved the camera this
     * tick — making the interpolation correct regardless of which system drove it.
     */
    public function update(World $world, float $dt): void
    {
        if (!$this->interpolate) {
            return;
        }

        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $cam = $entity->get(Camera3DComponent::class);
            if (!$cam->active) {
                continue;
            }
            $transform = $entity->get(Transform3D::class);
            $pos = $transform->getWorldPosition();
            $rot = $transform->rotation;

            $prev = $this->interp[$entity->id] ?? null;
            if ($prev === null) {
                // First sample: previous == current (no movement to interpolate).
                $this->interp[$entity->id] = ['ppos' => $pos, 'prot' => $rot, 'cpos' => $pos, 'crot' => $rot];
            } else {
                // Shift last frame's current into previous, store the new current.
                $this->interp[$entity->id] = ['ppos' => $prev['cpos'], 'prot' => $prev['crot'], 'cpos' => $pos, 'crot' => $rot];
            }
            break; // Only one active camera
        }
    }

    public function render(World $world): void
    {
        if ($this->window !== null) {
            $w = $this->window->getFramebufferWidth();
            $h = $this->window->getFramebufferHeight();
            if ($w > 0 && $h > 0) {
                $this->viewportWidth = $w;
                $this->viewportHeight = $h;
            }
        }

        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $cam = $entity->get(Camera3DComponent::class);
            if (!$cam->active) {
                continue;
            }

            $transform = $entity->get(Transform3D::class);
            $worldPos = $transform->getWorldPosition();
            $rotation = $transform->rotation;

            // Fixed-timestep interpolation: place the camera between the previous
            // and latest sim tick by the render fraction, so it moves smoothly at
            // any render rate instead of snapping in 60 Hz steps (which strobes
            // the bright sun at 144 fps).
            $ip = $this->interp[$entity->id] ?? null;
            if ($this->interpolate && $ip !== null && $this->engine !== null) {
                $alpha = $this->engine->renderInterpolation;
                if ($alpha < 0.0) { $alpha = 0.0; } elseif ($alpha > 1.0) { $alpha = 1.0; }
                $jx = $ip['cpos']->x - $ip['ppos']->x;
                $jy = $ip['cpos']->y - $ip['ppos']->y;
                $jz = $ip['cpos']->z - $ip['ppos']->z;
                if ($jx * $jx + $jy * $jy + $jz * $jz < self::SNAP_DIST_SQ) {
                    $worldPos = $ip['ppos']->lerp($ip['cpos'], $alpha);
                    $rotation = $ip['prot']->slerp($ip['crot'], $alpha);
                } else {
                    $worldPos = $ip['cpos']; // teleport — snap, don't smear
                    $rotation = $ip['crot'];
                }
            }

            $eyeOffset = $cam->eyeOffset;
            if ($eyeOffset->x !== 0.0 || $eyeOffset->y !== 0.0 || $eyeOffset->z !== 0.0) {
                $worldPos = $worldPos->add($eyeOffset);
            }

            $forward = $rotation->rotateVec3(new Vec3(0.0, 0.0, -1.0));
            $up      = $rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0));

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
