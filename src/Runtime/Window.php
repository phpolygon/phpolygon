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

    /** Stored so callbacks can be restored after a glfwSetWindowMonitor call. */
    private ?Input $input = null;

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

        $this->input = $input;
        $this->attachRealCallbacks();

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
        // Poll window and framebuffer size every frame instead of relying on
        // glfwSetFramebufferSizeCallback / glfwSetWindowSizeCallback.
        // Those callbacks are invoked re-entrantly during glfwSetWindowMonitor
        // while the PHP value stack is inconsistent, corrupting adjacent zvals
        // regardless of what the closure body does. Polling here is equivalent
        // for a single-window application and eliminates the corruption entirely.
        $w = 0;
        $h = 0;
        glfwGetWindowSize($this->handle, $w, $h);
        if (is_int($w) && $w > 0) {
            $this->width = $w;
        }
        if (is_int($h) && $h > 0) {
            $this->height = $h;
        }
        $fbW = 0;
        $fbH = 0;
        glfwGetFramebufferSize($this->handle, $fbW, $fbH);
        if (is_int($fbW) && $fbW > 0) {
            $this->framebufferWidth = $fbW;
        }
        if (is_int($fbH) && $fbH > 0) {
            $this->framebufferHeight = $fbH;
        }
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

        // Swap to static no-op callbacks so re-entrant GLFW invocations during
        // glfwSetWindowMonitor cannot corrupt PHP zvals. Restored immediately after.
        $this->attachNoOpCallbacks();

        glfwSetWindowMonitor(
            $this->handle,
            $monitor,
            0,
            0,
            $modeWidth,
            $modeHeight,
            $modeRefresh,
        );

        $this->attachRealCallbacks();

        // macOS sets the window-should-close flag as a side effect of the AppKit
        // fullscreen animation. Reset it so the game loop does not exit.
        glfwSetWindowShouldClose($this->handle, 0);

        $this->readFramebufferSize();
        $this->width  = $modeWidth;
        $this->height = $modeHeight;

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
            $this->attachNoOpCallbacks();
            glfwSetWindowMonitor(
                $this->handle,
                null,
                $this->windowedX,
                $this->windowedY,
                $this->windowedWidth,
                $this->windowedHeight,
                0,
            );
            $this->attachRealCallbacks();
            glfwSetWindowShouldClose($this->handle, 0);
            $this->readFramebufferSize();
            $this->width = $this->windowedWidth;
            $this->height = $this->windowedHeight;
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
            $this->attachNoOpCallbacks();
            glfwSetWindowMonitor(
                $this->handle,
                null,
                $this->windowedX,
                $this->windowedY,
                $this->windowedWidth,
                $this->windowedHeight,
                0,
            );
            $this->attachRealCallbacks();
            glfwSetWindowShouldClose($this->handle, 0);
            $this->readFramebufferSize();
            $this->width = $this->windowedWidth;
            $this->height = $this->windowedHeight;
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
     * Replace all GLFW input callbacks with static no-ops before a blocking
     * glfwSetWindowMonitor call. php-glfw invokes registered PHP closures
     * re-entrantly during the call while the PHP value stack is inconsistent;
     * the integer arguments ($width, $height, $refreshRate …) corrupt adjacent
     * zvals (e.g. World::$components, Input::$keyPressedThisFrame).
     * Static no-ops have no captures and no writes — zero corruption damage.
     */
    private function attachNoOpCallbacks(): void
    {
        glfwSetKeyCallback($this->handle, static function (int $key, int $scancode, int $action, int $mods): void {});
        glfwSetMouseButtonCallback($this->handle, static function (int $button, int $action, int $mods): void {});
        glfwSetCursorPosCallback($this->handle, static function (float $x, float $y): void {});
        glfwSetScrollCallback($this->handle, static function (float $xOffset, float $yOffset): void {});
        glfwSetCharCallback($this->handle, static function (int $codepoint): void {});
    }

    /** Restore real input callbacks after a glfwSetWindowMonitor call. */
    private function attachRealCallbacks(): void
    {
        $input = $this->input;
        if ($input === null) {
            return;
        }
        glfwSetKeyCallback($this->handle, function (int $key, int $scancode, int $action, int $mods) use ($input): void {
            $input->handleKeyEvent($key, $action);
        });
        glfwSetMouseButtonCallback($this->handle, function (int $button, int $action, int $mods) use ($input): void {
            $input->handleMouseButtonEvent($button, $action);
        });
        glfwSetCursorPosCallback($this->handle, function (float $x, float $y) use ($input): void {
            $input->handleCursorPosEvent($x, $y);
        });
        glfwSetScrollCallback($this->handle, function (float $xOffset, float $yOffset) use ($input): void {
            $input->handleScrollEvent($xOffset, $yOffset);
        });
        glfwSetCharCallback($this->handle, function (int $codepoint) use ($input): void {
            $input->handleCharEvent($codepoint);
        });
    }

    /**
     * Re-read actual framebuffer dimensions from GLFW after a mode switch.
     * pollEvents() handles the per-frame update; this is for the immediate
     * post-switch read before the next frame.
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
