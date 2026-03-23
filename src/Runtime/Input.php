<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Math\Vec2;

class Input
{
    /** @var array<int, bool> Current frame key state */
    private array $keysDown = [];

    /** @var array<int, bool> Previous frame key state */
    private array $keysPrev = [];

    /** @var array<int, bool> Current frame mouse button state */
    private array $mouseDown = [];

    /** @var array<int, bool> Previous frame mouse button state */
    private array $mousePrev = [];

    private float $mouseX = 0.0;
    private float $mouseY = 0.0;
    private float $scrollX = 0.0;
    private float $scrollY = 0.0;

    public function handleKeyEvent(int $key, int $action): void
    {
        // GLFW_PRESS = 1, GLFW_RELEASE = 0, GLFW_REPEAT = 2
        $this->keysDown[$key] = $action !== 0; // GLFW_RELEASE
    }

    public function handleMouseButtonEvent(int $button, int $action): void
    {
        $this->mouseDown[$button] = $action !== 0;
    }

    public function handleCursorPosEvent(float $x, float $y): void
    {
        $this->mouseX = $x;
        $this->mouseY = $y;
    }

    public function handleScrollEvent(float $xOffset, float $yOffset): void
    {
        $this->scrollX += $xOffset;
        $this->scrollY += $yOffset;
    }

    public function isKeyDown(int $key): bool
    {
        return $this->keysDown[$key] ?? false;
    }

    public function isKeyPressed(int $key): bool
    {
        return ($this->keysDown[$key] ?? false) && !($this->keysPrev[$key] ?? false);
    }

    public function isKeyReleased(int $key): bool
    {
        return !($this->keysDown[$key] ?? false) && ($this->keysPrev[$key] ?? false);
    }

    public function isMouseButtonDown(int $button): bool
    {
        return $this->mouseDown[$button] ?? false;
    }

    public function isMouseButtonPressed(int $button): bool
    {
        return ($this->mouseDown[$button] ?? false) && !($this->mousePrev[$button] ?? false);
    }

    public function isMouseButtonReleased(int $button): bool
    {
        return !($this->mouseDown[$button] ?? false) && ($this->mousePrev[$button] ?? false);
    }

    public function getMousePosition(): Vec2
    {
        return new Vec2($this->mouseX, $this->mouseY);
    }

    public function getMouseX(): float
    {
        return $this->mouseX;
    }

    public function getMouseY(): float
    {
        return $this->mouseY;
    }

    public function getScrollX(): float
    {
        return $this->scrollX;
    }

    public function getScrollY(): float
    {
        return $this->scrollY;
    }

    public function endFrame(): void
    {
        $this->keysPrev = $this->keysDown;
        $this->mousePrev = $this->mouseDown;
        $this->scrollX = 0.0;
        $this->scrollY = 0.0;
    }
}
