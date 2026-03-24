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

    /** @var list<string> Characters typed this frame (UTF-8) */
    private array $charBuffer = [];

    /** When true, key/mouse events are not recorded (UI is consuming input) */
    private bool $suppressed = false;
    private int $suppressFrames = 0;
    private float $suppressUntil = 0.0;

    public function handleKeyEvent(int $key, int $action): void
    {
        if ($this->isSuppressed()) {
            return;
        }
        // GLFW_PRESS = 1, GLFW_RELEASE = 0, GLFW_REPEAT = 2
        $this->keysDown[$key] = $action !== 0; // GLFW_RELEASE
    }

    public function handleMouseButtonEvent(int $button, int $action): void
    {
        if ($this->isSuppressed()) {
            return;
        }
        $this->mouseDown[$button] = $action !== 0;
    }

    public function handleCharEvent(int $codepoint): void
    {
        $this->charBuffer[] = mb_chr($codepoint, 'UTF-8');
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

    /**
     * Get all characters typed this frame.
     *
     * @return list<string>
     */
    public function getCharsTyped(): array
    {
        return $this->charBuffer;
    }

    /**
     * Get typed characters as a single concatenated string.
     */
    public function getTextInput(): string
    {
        return implode('', $this->charBuffer);
    }

    /**
     * Suppress game input (key/mouse). Char events still pass through.
     *
     * @param int   $frames  Number of frames to suppress (0 = until unsuppress())
     * @param float $seconds Time-based suppression (0 = frame-based only)
     */
    public function suppress(int $frames = 0, float $seconds = 0.0): void
    {
        $this->suppressed = true;
        if ($frames > 0) {
            $this->suppressFrames = $frames;
        }
        if ($seconds > 0.0) {
            $this->suppressUntil = microtime(true) + $seconds;
        }
        // Only clear input state for timed suppression (display mode changes).
        // Simple suppress (no args) is used by UI hover and should not wipe state.
        if ($frames > 0 || $seconds > 0.0) {
            $this->keysDown = [];
            $this->mouseDown = [];
        }
    }

    /**
     * Re-enable game input processing.
     */
    public function unsuppress(): void
    {
        $this->suppressed = false;
        $this->suppressFrames = 0;
        $this->suppressUntil = 0.0;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed || $this->suppressFrames > 0 || microtime(true) < $this->suppressUntil;
    }

    public function endFrame(): void
    {
        $this->keysPrev = $this->keysDown;
        $this->mousePrev = $this->mouseDown;
        $this->scrollX = 0.0;
        $this->scrollY = 0.0;
        $this->charBuffer = [];

        // Frame-based suppression countdown
        if ($this->suppressFrames > 0) {
            $this->suppressFrames--;
            if ($this->suppressFrames <= 0 && microtime(true) >= $this->suppressUntil) {
                $this->suppressed = false;
            }
        }
    }
}
