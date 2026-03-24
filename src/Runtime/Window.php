<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use RuntimeException;

class Window
{
    /** @var \GLFWwindow */
    private object $handle;

    private int $framebufferWidth = 0;
    private int $framebufferHeight = 0;
    private float $contentScaleX = 1.0;
    private float $contentScaleY = 1.0;
    private bool $initialized = false;
    private bool $fullscreen = false;

    /** Stored windowed position/size for restoring after fullscreen */
    private int $windowedX = 0;
    private int $windowedY = 0;
    private int $windowedWidth = 0;
    private int $windowedHeight = 0;

    public function __construct(
        private int $width,
        private int $height,
        private string $title,
        private bool $vsync = true,
        private bool $resizable = true,
    ) {}

    public function initialize(Input $input): void
    {
        if (!glfwInit()) {
            throw new RuntimeException('Failed to initialize GLFW');
        }

        glfwWindowHint(GLFW_CONTEXT_VERSION_MAJOR, 4);
        glfwWindowHint(GLFW_CONTEXT_VERSION_MINOR, 1);
        glfwWindowHint(GLFW_OPENGL_PROFILE, GLFW_OPENGL_CORE_PROFILE);
        glfwWindowHint(GLFW_OPENGL_FORWARD_COMPAT, GL_TRUE);
        glfwWindowHint(GLFW_RESIZABLE, $this->resizable ? GL_TRUE : GL_FALSE);
        glfwWindowHint(GLFW_VISIBLE, GL_FALSE);

        $this->handle = glfwCreateWindow($this->width, $this->height, $this->title, null, null);

        glfwMakeContextCurrent($this->handle);
        glfwSwapInterval($this->vsync ? 1 : 0);

        // Get content scale for HiDPI
        $csX = 1.0;
        $csY = 1.0;
        glfwGetWindowContentScale($this->handle, $csX, $csY);
        $this->contentScaleX = is_float($csX) ? $csX : (is_int($csX) ? (float) $csX : 1.0);
        $this->contentScaleY = is_float($csY) ? $csY : (is_int($csY) ? (float) $csY : 1.0);

        // Get actual framebuffer size
        $fbW = 0;
        $fbH = 0;
        glfwGetFramebufferSize($this->handle, $fbW, $fbH);
        $this->framebufferWidth = is_int($fbW) ? $fbW : 0;
        $this->framebufferHeight = is_int($fbH) ? $fbH : 0;

        // Set up input callbacks (php-glfw does NOT pass $window as first arg)
        glfwSetKeyCallback($this->handle, function (int $key, int $scancode, int $action, int $mods) use ($input) {
            $input->handleKeyEvent($key, $action);
        });

        glfwSetMouseButtonCallback($this->handle, function (int $button, int $action, int $mods) use ($input) {
            $input->handleMouseButtonEvent($button, $action);
        });

        glfwSetCursorPosCallback($this->handle, function (float $x, float $y) use ($input) {
            $input->handleCursorPosEvent($x, $y);
        });

        glfwSetScrollCallback($this->handle, function (float $xOffset, float $yOffset) use ($input) {
            $input->handleScrollEvent($xOffset, $yOffset);
        });

        glfwSetCharCallback($this->handle, function (int $codepoint) use ($input) {
            $input->handleCharEvent($codepoint);
        });

        glfwSetFramebufferSizeCallback($this->handle, function (int $width, int $height) {
            $this->framebufferWidth = $width;
            $this->framebufferHeight = $height;
        });

        glfwSetWindowSizeCallback($this->handle, function (int $width, int $height) {
            $this->width = $width;
            $this->height = $height;
        });

        glfwShowWindow($this->handle);
        $this->initialized = true;
    }

    public function shouldClose(): bool
    {
        return (bool)glfwWindowShouldClose($this->handle);
    }

    public function pollEvents(): void
    {
        glfwPollEvents();
    }

    public function swapBuffers(): void
    {
        glfwSwapBuffers($this->handle);
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getFramebufferWidth(): int
    {
        return $this->framebufferWidth;
    }

    public function getFramebufferHeight(): int
    {
        return $this->framebufferHeight;
    }

    public function getContentScaleX(): float
    {
        return $this->contentScaleX;
    }

    public function getContentScaleY(): float
    {
        return $this->contentScaleY;
    }

    public function getPixelRatio(): float
    {
        if ($this->width === 0) {
            return 1.0;
        }
        return $this->framebufferWidth / $this->width;
    }

    /** @return \GLFWwindow */
    public function getHandle(): object
    {
        return $this->handle;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        glfwSetWindowTitle($this->handle, $title);
    }

    public function isFullscreen(): bool
    {
        return $this->fullscreen;
    }

    /**
     * Switch to borderless fullscreen on the primary monitor.
     */
    public function setFullscreen(): void
    {
        if ($this->fullscreen) {
            return;
        }

        // Save windowed geometry for later restore
        $wx = 0;
        $wy = 0;
        glfwGetWindowPos($this->handle, $wx, $wy);
        $this->windowedX = is_int($wx) ? $wx : 0;
        $this->windowedY = is_int($wy) ? $wy : 0;
        $this->windowedWidth = $this->width;
        $this->windowedHeight = $this->height;

        $monitor = glfwGetPrimaryMonitor();
        $mode = glfwGetVideoMode($monitor);

        // GLFWvidmode properties are provided by the php-glfw extension
        // but not recognized by PHPStan stubs
        /** @var int $modeWidth */
        $modeWidth = $mode->width; // @phpstan-ignore property.notFound
        /** @var int $modeHeight */
        $modeHeight = $mode->height; // @phpstan-ignore property.notFound
        /** @var int $modeRefresh */
        $modeRefresh = $mode->refreshRate; // @phpstan-ignore property.notFound
        glfwSetWindowMonitor(
            $this->handle,
            $monitor,
            0,
            0,
            $modeWidth,
            $modeHeight,
            $modeRefresh,
        );

        $this->fullscreen = true;
    }

    /**
     * Return to windowed mode with the previous window size and position.
     */
    public function setWindowed(): void
    {
        if (!$this->fullscreen) {
            return;
        }

        glfwSetWindowMonitor(
            $this->handle,
            null,
            $this->windowedX,
            $this->windowedY,
            $this->windowedWidth,
            $this->windowedHeight,
            0,
        );

        $this->fullscreen = false;
    }

    /**
     * Resize the window (only in windowed mode).
     */
    public function setSize(int $width, int $height): void
    {
        glfwSetWindowSize($this->handle, $width, $height);
        $this->width = $width;
        $this->height = $height;
        $this->windowedWidth = $width;
        $this->windowedHeight = $height;
    }

    /**
     * Toggle between fullscreen and windowed mode.
     */
    public function toggleFullscreen(): void
    {
        if ($this->fullscreen) {
            $this->setWindowed();
        } else {
            $this->setFullscreen();
        }
    }

    public function destroy(): void
    {
        if ($this->initialized) {
            glfwDestroyWindow($this->handle);
            glfwTerminate();
            $this->initialized = false;
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
