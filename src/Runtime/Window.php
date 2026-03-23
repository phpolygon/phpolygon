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
        glfwGetWindowContentScale($this->handle, $this->contentScaleX, $this->contentScaleY);

        // Get actual framebuffer size
        glfwGetFramebufferSize($this->handle, $this->framebufferWidth, $this->framebufferHeight);

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
