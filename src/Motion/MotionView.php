<?php

declare(strict_types=1);

namespace PHPolygon\Motion;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ViewVolume;

/**
 * The camera a motion system freezes against: the eye and the frustum planes of
 * the last rendered frame. A system captures it in its render phase (from the
 * queued {@see SetCamera}) and asks it in the next update, the way
 * {@see \PHPolygon\System\ParticleSystem} remembers its camera, so the answer is
 * one frame old. Without a camera everything counts as seen: nothing freezes
 * before the first frame, in tools or in tests that never set one.
 *
 * Phase 3 of the visibility plan listed this class as one of six systems
 * computing its own frustum - it now builds the SAME {@see ViewVolume} value
 * object {@see \PHPolygon\Visibility\VisibilityService::frame()} returns,
 * instead of holding a raw planes array, so there is one shared frustum type
 * (not one shared LIVE instance: see below). It deliberately does NOT read a
 * live VisibilityService - that service's state reflects the CURRENT frame's
 * camera (or none at all between beginFrame() calls), while MotionView's
 * whole purpose is to stay frozen at the PREVIOUS frame's camera, including
 * through a frame where capture() sees no SetCamera at all (see
 * testCaptureWithoutASetCameraKeepsTheOldOneInstead in MotionViewTest) - a
 * live VisibilityService::frame() would answer null then and unfreeze
 * everything, which is exactly the bug this class exists to avoid.
 */
final class MotionView
{
    private ?Vec3 $eye = null;

    private ?ViewVolume $volume = null;

    /** Remember the last camera queued in $commands; keeps the old one when there is none. */
    public function capture(RenderCommandList $commands): void
    {
        $camera = $commands->lastOfType(SetCamera::class);
        if ($camera instanceof SetCamera) {
            $this->set($camera->viewMatrix, $camera->projectionMatrix);
        }
    }

    public function set(Mat4 $view, Mat4 $projection): void
    {
        $this->eye = $view->inverse()->getTranslation();
        $this->volume = new ViewVolume($view, $projection, Frustum::planes($projection, $view), $this->eye);
    }

    /** A camera that only has a position (distance limits, no frustum test). */
    public function setEye(?Vec3 $eye): void
    {
        $this->eye = $eye;
        $this->volume = null;
    }

    public function clear(): void
    {
        $this->eye = null;
        $this->volume = null;
    }

    public function eye(): ?Vec3
    {
        return $this->eye;
    }

    /**
     * Whether a sphere is worth animating: inside the frustum and, with $maxDistance
     * > 0, no further than that from the eye (measured to the sphere's surface).
     * $radius <= 0 means "never freeze".
     */
    public function sees(Vec3 $center, float $radius, float $maxDistance = 0.0): bool
    {
        if ($radius <= 0.0 || $this->eye === null) {
            return true;
        }
        if ($maxDistance > 0.0) {
            $dx = $center->x - $this->eye->x;
            $dy = $center->y - $this->eye->y;
            $dz = $center->z - $this->eye->z;
            $reach = $maxDistance + $radius;
            if ($dx * $dx + $dy * $dy + $dz * $dz > $reach * $reach) {
                return false;
            }
        }
        if ($this->volume === null) {
            return true;
        }
        return $this->volume->containsSphere($center->x, $center->y, $center->z, $radius);
    }
}
