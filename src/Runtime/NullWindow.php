<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * A window implementation that requires no GPU or display server.
 * Used for headless mode: testing, validation, CI, editor dry-runs.
 */
class NullWindow extends Window
{
    private bool $shouldCloseFlag = false;
    private int $nullWidth;
    private int $nullHeight;
    private bool $nullFullscreen = false;
    private bool $nullBorderless = false;
    private int $nullWindowedWidth = 0;
    private int $nullWindowedHeight = 0;

    public function __construct(
        int $width = 1280,
        int $height = 720,
        string $title = 'PHPolygon (headless)',
    ) {
        parent::__construct($width, $height, $title, false, false);
        $this->nullWidth = $width;
        $this->nullHeight = $height;
        $this->nullWindowedWidth = $width;
        $this->nullWindowedHeight = $height;
    }

    public function initialize(InputInterface $input): void
    {
        // No GLFW, no GL context — just mark as ready
    }

    public function shouldClose(): bool
    {
        return $this->shouldCloseFlag;
    }

    public function requestClose(): void
    {
        $this->shouldCloseFlag = true;
    }

    public function pollEvents(): void {}
    public function swapBuffers(): void {}

    public function getWidth(): int { return $this->nullWidth; }
    public function getHeight(): int { return $this->nullHeight; }
    public function getFramebufferWidth(): int { return $this->nullWidth; }
    public function getFramebufferHeight(): int { return $this->nullHeight; }
    public function getContentScaleX(): float { return 1.0; }
    public function getContentScaleY(): float { return 1.0; }
    public function getPixelRatio(): float { return 1.0; }
    public function getHandle(): object { throw new \RuntimeException('No window handle in headless mode'); }
    public function setTitle(string $title): void {}

    public function setFullscreen(): void
    {
        if ($this->nullFullscreen) {
            return;
        }
        if (!$this->nullBorderless) {
            $this->nullWindowedWidth  = $this->nullWidth;
            $this->nullWindowedHeight = $this->nullHeight;
        }
        $this->nullFullscreen = true;
        $this->nullBorderless = false;
    }

    public function setBorderless(): void
    {
        if ($this->nullBorderless) {
            return;
        }
        if (!$this->nullFullscreen) {
            $this->nullWindowedWidth  = $this->nullWidth;
            $this->nullWindowedHeight = $this->nullHeight;
        }
        $this->nullFullscreen = false;
        $this->nullBorderless = true;
    }

    public function setWindowed(): void
    {
        if (!$this->nullFullscreen && !$this->nullBorderless) {
            return;
        }
        $this->nullWidth      = $this->nullWindowedWidth;
        $this->nullHeight     = $this->nullWindowedHeight;
        $this->nullFullscreen = false;
        $this->nullBorderless = false;
    }

    public function setSize(int $width, int $height): void
    {
        $this->nullWidth  = $width;
        $this->nullHeight = $height;
        if (!$this->nullFullscreen && !$this->nullBorderless) {
            $this->nullWindowedWidth  = $width;
            $this->nullWindowedHeight = $height;
        }
    }

    public function toggleFullscreen(): void
    {
        if ($this->nullFullscreen) {
            $this->setWindowed();
        } else {
            $this->setFullscreen();
        }
    }

    public function isFullscreen(): bool { return $this->nullFullscreen; }
    public function isBorderless(): bool { return $this->nullBorderless; }
    public function destroy(): void {}
}
