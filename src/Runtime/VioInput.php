<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Math\Vec2;
use VioContext;

class VioInput implements InputInterface
{
    private ?VioContext $ctx = null;

    /**
     * Buffered key press events from GLFW callback. Survives across multiple
     * render frames until consumed by isKeyPressed(). This decouples edge
     * detection from the render frame rate (vio_begin resets C-level keys_prev
     * every render, but the fixed timestep may run fewer updates than renders).
     *
     * @var array<int, bool>
     */
    private array $keyJustPressed = [];

    /** @var array<int, bool> */
    private array $keyJustReleased = [];

    /** @var array<int, bool> Previous frame mouse button state */
    private array $mousePrev = [];

    /** @var array<int, bool> Buffered press edges (survive across render frames) */
    private array $mouseJustPressed = [];

    /** @var array<int, bool> Buffered release edges (survive across render frames) */
    private array $mouseJustReleased = [];

    /** @var list<string> Characters typed this frame */
    private array $charBuffer = [];

    /** Cached scroll deltas — snapshot taken before vio_begin resets them */
    private float $cachedScrollX = 0.0;
    private float $cachedScrollY = 0.0;

    /**
     * Snapshot scroll deltas from the C context. Must be called BEFORE
     * renderer2D->beginFrame() (which calls vio_begin and resets scroll to 0).
     */
    public function snapshotScroll(): void
    {
        if ($this->ctx !== null) {
            $scroll = vio_mouse_scroll($this->ctx);
            $this->cachedScrollX = $scroll[0];
            $this->cachedScrollY = $scroll[1];
        }
    }

    private bool $suppressed = false;
    private int $suppressFrames = 0;
    private float $suppressUntil = 0.0;

    public function setContext(VioContext $ctx): void
    {
        $this->ctx = $ctx;

        vio_on_key($ctx, function (int $key, int $action, int $mods): void {
            if ($action === 1) { // GLFW_PRESS
                $this->keyJustPressed[$key] = true;
            } elseif ($action === 0) { // GLFW_RELEASE
                $this->keyJustReleased[$key] = true;
            }
        });

        vio_on_char($ctx, function (int $codepoint): void {
            $this->charBuffer[] = mb_chr($codepoint, 'UTF-8');
        });
    }

    public function isKeyDown(int $key): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }
        return vio_key_pressed($this->ctx, $key);
    }

    public function isKeyPressed(int $key): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }
        if ($this->keyJustPressed[$key] ?? false) {
            unset($this->keyJustPressed[$key]);
            return true;
        }
        return false;
    }

    public function isKeyReleased(int $key): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }
        if ($this->keyJustReleased[$key] ?? false) {
            unset($this->keyJustReleased[$key]);
            return true;
        }
        return false;
    }

    public function isMouseButtonDown(int $button): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }
        return vio_mouse_button($this->ctx, $button);
    }

    public function isMouseButtonPressed(int $button): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }
        return $this->mouseJustPressed[$button] ?? false;
    }

    public function isMouseButtonReleased(int $button): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }
        return $this->mouseJustReleased[$button] ?? false;
    }

    public function getMousePosition(): Vec2
    {
        if ($this->ctx === null) {
            return new Vec2(0.0, 0.0);
        }
        $pos = vio_mouse_position($this->ctx);
        return new Vec2($pos[0], $pos[1]);
    }

    public function getMouseX(): float
    {
        if ($this->ctx === null) {
            return 0.0;
        }
        return vio_mouse_position($this->ctx)[0];
    }

    public function getMouseY(): float
    {
        if ($this->ctx === null) {
            return 0.0;
        }
        return vio_mouse_position($this->ctx)[1];
    }

    public function getScrollX(): float
    {
        return $this->cachedScrollX;
    }

    public function getScrollY(): float
    {
        return $this->cachedScrollY;
    }

    public function getCharsTyped(): array
    {
        return $this->charBuffer;
    }

    public function getBackspaceCount(): int
    {
        if ($this->ctx === null) {
            return 0;
        }
        return vio_ime_backspaces($this->ctx);
    }

    public function showSoftKeyboard(): void
    {
        if ($this->ctx !== null) {
            vio_keyboard_show($this->ctx);
        }
    }

    public function hideSoftKeyboard(): void
    {
        if ($this->ctx !== null) {
            vio_keyboard_hide($this->ctx);
        }
    }

    public function getTextInput(): string
    {
        return implode('', $this->charBuffer);
    }

    public function suppress(int $frames = 0, float $seconds = 0.0): void
    {
        $this->suppressed = true;
        if ($frames > 0) {
            $this->suppressFrames = $frames;
        }
        if ($seconds > 0.0) {
            $this->suppressUntil = microtime(true) + $seconds;
        }
    }

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

    /**
     * Drop any buffered "just pressed" / "just released" key edges that no
     * system consumed. Call this when handing input back to gameplay from a
     * modal (e.g. closing the code editor) so a key typed into the modal — a
     * Space goes in as a character, not a consumed key event — can't linger in
     * the buffer and fire as a jump the moment the modal closes.
     *
     * Key edges are otherwise *not* cleared per frame: isKeyPressed() consumes
     * them on read, and leaving unread presses buffered is deliberate — it lets
     * a jump pressed a hair before landing still fire (the controller only
     * reads Space once it's grounded).
     */
    public function clearKeyEdges(): void
    {
        $this->keyJustPressed = [];
        $this->keyJustReleased = [];
    }

    public function endFrame(): void
    {
        // Clear previous frame's mouse edges, then detect new ones.
        // Mouse button state is polled (not callback-based like keys), so edges
        // are detected by comparing current vs prev. Edges are NOT consumed on
        // read — all callers within a frame see the same state (required for
        // immediate-mode UI where multiple widgets check the same button).
        $this->mouseJustPressed = [];
        $this->mouseJustReleased = [];
        if ($this->ctx !== null) {
            for ($i = 0; $i <= 7; $i++) {
                $current = vio_mouse_button($this->ctx, $i);
                $prev = $this->mousePrev[$i] ?? false;
                if ($current && !$prev) {
                    $this->mouseJustPressed[$i] = true;
                }
                if (!$current && $prev) {
                    $this->mouseJustReleased[$i] = true;
                }
                $this->mousePrev[$i] = $current;
            }
        }

        $this->charBuffer = [];

        if ($this->suppressed) {
            if ($this->suppressFrames > 0) {
                $this->suppressFrames--;
            }
            $framesExpired = $this->suppressFrames <= 0;
            $timeExpired = $this->suppressUntil <= 0.0 || microtime(true) >= $this->suppressUntil;
            if ($framesExpired && $timeExpired) {
                $this->suppressed = false;
                $this->suppressUntil = 0.0;
            }
        }
    }
}
