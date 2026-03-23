<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Vec2;

class Camera2D
{
    public Vec2 $position;
    public float $zoom = 1.0;

    private int $viewportWidth;
    private int $viewportHeight;

    public function __construct(int $viewportWidth = 1280, int $viewportHeight = 720)
    {
        $this->position = Vec2::zero();
        $this->viewportWidth = $viewportWidth;
        $this->viewportHeight = $viewportHeight;
    }

    public function setViewportSize(int $width, int $height): void
    {
        $this->viewportWidth = $width;
        $this->viewportHeight = $height;
    }

    public function getViewMatrix(): Mat3
    {
        $centerX = $this->viewportWidth * 0.5;
        $centerY = $this->viewportHeight * 0.5;

        // Translate to center, apply zoom, then offset by camera position
        return Mat3::translation($centerX, $centerY)
            ->multiply(Mat3::scaling($this->zoom, $this->zoom))
            ->multiply(Mat3::translation(-$this->position->x, -$this->position->y));
    }

    public function screenToWorld(Vec2 $screen): Vec2
    {
        $centerX = $this->viewportWidth * 0.5;
        $centerY = $this->viewportHeight * 0.5;

        return new Vec2(
            ($screen->x - $centerX) / $this->zoom + $this->position->x,
            ($screen->y - $centerY) / $this->zoom + $this->position->y,
        );
    }

    public function worldToScreen(Vec2 $world): Vec2
    {
        $centerX = $this->viewportWidth * 0.5;
        $centerY = $this->viewportHeight * 0.5;

        return new Vec2(
            ($world->x - $this->position->x) * $this->zoom + $centerX,
            ($world->y - $this->position->y) * $this->zoom + $centerY,
        );
    }

    public function getViewportWidth(): int
    {
        return $this->viewportWidth;
    }

    public function getViewportHeight(): int
    {
        return $this->viewportHeight;
    }
}
