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
    private bool $borderless = false;

    /**
     * Timestamp set when entering fullscreen or borderless mode.
     * shouldClose() suppresses the close flag for SUPPRESS_CLOSE_SECS seconds
     * after a mode switch so macOS AppKit's deferred "window close" event
     * (fired at the end of the fullscreen animation) does not terminate the loop.
     */
    private float $suppressCloseUntil = 0.0;
    private const float SUPPRESS_CLOSE_SECS = 2.0;

    /** Stored windowed position/size for restoring after fullscreen or borderless */
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
        private bool $noApi = false, // true for Vulkan/Metal — disables OpenGL context creation
    ) {}

    public function initialize(Input $input): void
    {
        if (!glfwInit()) {
            throw new RuntimeException('Failed to initialize GLFW');
        }

        if ($this->noApi) {
            // Vulkan / Metal: no OpenGL context — the native backend manages the swapchain
            glfwWindowHint(GLFW_CLIENT_API, GLFW_NO_API);
        } else {
            glfwWindowHint(GLFW_CONTEXT_VERSION_MAJOR, 4);
            glfwWindowHint(GLFW_CONTEXT_VERSION_MINOR, 1);
            glfwWindowHint(GLFW_OPENGL_PROFILE, GLFW_OPENGL_CORE_PROFILE);
            glfwWindowHint(GLFW_OPENGL_FORWARD_COMPAT, GL_TRUE);
            glfwWindowHint(GLFW_SAMPLES, 4);
        }
        glfwWindowHint(GLFW_RESIZABLE, $this->resizable ? GL_TRUE : GL_FALSE);
        glfwWindowHint(GLFW_VISIBLE, GL_FALSE);

        $this->handle = glfwCreateWindow($this->width, $this->height, $this->title, null, null);

        if (!$this->noApi) {
            glfwMakeContextCurrent($this->handle);
            glfwSwapInterval($this->vsync ? 1 : 0);
        }

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

        $this->attachSizeCallbacks();

        glfwShowWindow($this->handle);
        $this->initialized = true;
    }

    public function shouldClose(): bool
    {
        $glfwFlag = (bool)glfwWindowShouldClose($this->handle);
        $now      = microtime(true);
        if ($now < $this->suppressCloseUntil) {
            glfwSetWindowShouldClose($this->handle, 0);
            return false;
        }
        return $glfwFlag;
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

    public function isBorderless(): bool
    {
        return $this->borderless;
    }

    /**
     * Switch to exclusive fullscreen on the primary monitor.
     */
    public function setFullscreen(): void
    {
        if ($this->fullscreen) {
            return;
        }

        if ($this->borderless) {
            glfwSetWindowAttrib($this->handle, GLFW_DECORATED, GL_TRUE);
            $this->borderless = false;
        } else {
            $wx = 0;
            $wy = 0;
            glfwGetWindowPos($this->handle, $wx, $wy);
            $this->windowedX = is_int($wx) ? $wx : 0;
            $this->windowedY = is_int($wy) ? $wy : 0;
            $this->windowedWidth = $this->width;
            $this->windowedHeight = $this->height;
        }

        $monitor = glfwGetPrimaryMonitor();
        $mode = glfwGetVideoMode($monitor);

        /** @var int $modeWidth */
        $modeWidth = $mode->width; // @phpstan-ignore property.notFound
        /** @var int $modeHeight */
        $modeHeight = $mode->height; // @phpstan-ignore property.notFound
        /** @var int $modeRefresh */
        $modeRefresh = $mode->refreshRate; // @phpstan-ignore property.notFound

        // Arm the close-suppression timer before the mode switch so that any
        // deferred AppKit "window close" events are discarded by shouldClose().
        $this->suppressCloseUntil = microtime(true) + self::SUPPRESS_CLOSE_SECS;

        // Replace size callbacks with static no-ops before the blocking call.
        // php-glfw invokes PHP closures synchronously during the AppKit animation
        // while the PHP value stack is inconsistent. The integer arguments to the
        // size callbacks ($width=1792, $height=1120) land in wrong stack positions
        // and corrupt adjacent zvals (arrays become ints). Static no-op closures
        // have no captures and no writes, eliminating the corruption entirely.
        $this->attachNoOpSizeCallbacks();

        glfwSetWindowMonitor(
            $this->handle,
            $monitor,
            0,
            0,
            $modeWidth,
            $modeHeight,
            $modeRefresh,
        );

        // macOS sets the window-should-close flag as a side effect of the AppKit
        // fullscreen animation. Reset it so the game loop does not exit.
        glfwSetWindowShouldClose($this->handle, 0);

        // Read actual post-switch dimensions and restore real callbacks.
        $this->readFramebufferSize();
        $this->width  = $modeWidth;
        $this->height = $modeHeight;
        $this->attachSizeCallbacks();

        $this->fullscreen = true;
    }

    /**
     * Borderless windowed fullscreen: removes window decorations and maximizes.
     * Uses glfwMaximizeWindow (not glfwSetWindowSize) to avoid triggering macOS
     * AppKit's automatic Spaces-fullscreen entry, which fires a deferred close event.
     */
    public function setBorderless(): void
    {
        if ($this->borderless) {
            return;
        }

        if ($this->fullscreen) {
            $this->attachNoOpSizeCallbacks();
            glfwSetWindowMonitor(
                $this->handle,
                null,
                $this->windowedX,
                $this->windowedY,
                $this->windowedWidth,
                $this->windowedHeight,
                0,
            );
            glfwSetWindowShouldClose($this->handle, 0);
            $this->readFramebufferSize();
            $this->width = $this->windowedWidth;
            $this->height = $this->windowedHeight;
            $this->attachSizeCallbacks();
            $this->fullscreen = false;
        } else {
            $wx = 0;
            $wy = 0;
            glfwGetWindowPos($this->handle, $wx, $wy);
            $this->windowedX = is_int($wx) ? $wx : 0;
            $this->windowedY = is_int($wy) ? $wy : 0;
            $this->windowedWidth = $this->width;
            $this->windowedHeight = $this->height;
        }

        $this->suppressCloseUntil = microtime(true) + self::SUPPRESS_CLOSE_SECS;

        // Remove decorations, then maximise — glfwMaximizeWindow is the macOS-safe
        // approach. Using glfwSetWindowPos+glfwSetWindowSize to exactly fill the screen
        // triggers AppKit's automatic Spaces-fullscreen entry and fires a deferred
        // window-close notification that exits the game loop.
        glfwSetWindowAttrib($this->handle, GLFW_DECORATED, GL_FALSE);
        glfwMaximizeWindow($this->handle);
        $this->borderless = true;
    }

    /**
     * Return to windowed mode with the previous window size and position.
     */
    public function setWindowed(): void
    {
        if ($this->fullscreen) {
            $this->suppressCloseUntil = microtime(true) + self::SUPPRESS_CLOSE_SECS;
            $this->attachNoOpSizeCallbacks();
            glfwSetWindowMonitor(
                $this->handle,
                null,
                $this->windowedX,
                $this->windowedY,
                $this->windowedWidth,
                $this->windowedHeight,
                0,
            );
            glfwSetWindowShouldClose($this->handle, 0);
            $this->readFramebufferSize();
            $this->width = $this->windowedWidth;
            $this->height = $this->windowedHeight;
            $this->attachSizeCallbacks();
            $this->fullscreen = false;
        } elseif ($this->borderless) {
            // glfwRestoreWindow un-maximizes before re-adding decorations; then
            // we reposition and resize to the saved windowed geometry.
            glfwRestoreWindow($this->handle);
            glfwSetWindowAttrib($this->handle, GLFW_DECORATED, GL_TRUE);
            glfwSetWindowPos($this->handle, $this->windowedX, $this->windowedY);
            glfwSetWindowSize($this->handle, $this->windowedWidth, $this->windowedHeight);
            $this->width = $this->windowedWidth;
            $this->height = $this->windowedHeight;
            $this->borderless = false;
        }
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

    public function setCursorDisabled(): void
    {
        glfwSetInputMode($this->handle, GLFW_CURSOR, GLFW_CURSOR_DISABLED);
    }

    public function setCursorNormal(): void
    {
        glfwSetInputMode($this->handle, GLFW_CURSOR, GLFW_CURSOR_NORMAL);
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    /**
     * Register real framebuffer/window-size callbacks that update internal state.
     * Called once from initialize() and again after every glfwSetWindowMonitor call.
     */
    private function attachSizeCallbacks(): void
    {
        glfwSetFramebufferSizeCallback($this->handle, function (int $width, int $height) {
            $this->framebufferWidth = $width;
            $this->framebufferHeight = $height;
        });
        glfwSetWindowSizeCallback($this->handle, function (int $width, int $height) {
            $this->width = $width;
            $this->height = $height;
        });
    }

    /**
     * Temporarily replace size callbacks with static no-ops.
     *
     * php-glfw invokes PHP closures synchronously during glfwSetWindowMonitor
     * while the PHP value stack is in an inconsistent re-entrant state. The
     * integer arguments passed to size callbacks ($width, $height) are pushed
     * onto the wrong stack positions, corrupting adjacent zvals — manifesting
     * as properties/arrays of unrelated objects (Input, EventDispatcher) being
     * overwritten with garbage integers.
     *
     * Static no-op closures have no object captures and no property writes,
     * so even if they are invoked on a broken stack the damage surface is zero.
     * Real callbacks are restored via attachSizeCallbacks() after the call.
     */
    private function attachNoOpSizeCallbacks(): void
    {
        glfwSetFramebufferSizeCallback($this->handle, static function (int $w, int $h): void {});
        glfwSetWindowSizeCallback($this->handle, static function (int $w, int $h): void {});
    }

    /**
     * Re-read actual framebuffer dimensions from GLFW after a mode switch.
     */
    private function readFramebufferSize(): void
    {
        $fbW = 0;
        $fbH = 0;
        glfwGetFramebufferSize($this->handle, $fbW, $fbH);
        $this->framebufferWidth  = is_int($fbW) ? $fbW : $this->framebufferWidth;
        $this->framebufferHeight = is_int($fbH) ? $fbH : $this->framebufferHeight;
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
