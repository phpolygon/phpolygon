<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Math\Vec2;
use VioContext;

class VioInput implements InputInterface
{
    private ?VioContext $ctx = null;

    /**
     * How long an unread key edge stays buffered, in seconds.
     *
     * Long enough that a press survives render frames without an update tick
     * (a fixed timestep may run fewer updates than renders) and that a jump
     * pressed a hair before landing still fires. Short enough that a press
     * nobody was listening for does not fire much later – e.g. an interact key
     * pressed in the open triggering the moment the player walks up to
     * something.
     */
    public const float KEY_BUFFER_SECONDS = 0.2;

    /**
     * Buffered key press events from GLFW callback: key => time of the press.
     * Survives across render frames until consumed by isKeyPressed() or until
     * it is older than {@see KEY_BUFFER_SECONDS}.
     *
     * @var array<int, float>
     */
    private array $keyJustPressed = [];

    /** @var array<int, float> */
    private array $keyJustReleased = [];

    /** @var array<int, bool> Previous frame mouse button state */
    private array $mousePrev = [];

    /** @var array<int, bool> Buffered press edges (survive across render frames) */
    private array $mouseJustPressed = [];

    /** @var array<int, bool> Buffered release edges (survive across render frames) */
    private array $mouseJustReleased = [];

    /** @var list<string> Characters typed this frame */
    private array $charBuffer = [];

    /**
     * @var array<int, float> Auto-repeat edges from a held key (GLFW_REPEAT), key => time.
     *
     * Held SEPARATE from $keyJustPressed on purpose. Mixing them would make a
     * held key fire every gameplay action that reads isKeyPressed() - jump,
     * interact, skip - once the OS repeat kicks in. Only text editing wants
     * repeats, and it asks for them via isKeyTyped().
     */
    private array $keyRepeated = [];

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
            $this->recordKeyEdge($key, $action, microtime(true));
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
        if (isset($this->keyJustPressed[$key])) {
            unset($this->keyJustPressed[$key]);
            return true;
        }
        return false;
    }

    /**
     * Buffer one GLFW key event (press 1, release 0, repeat 2) stamped with $now.
     *
     * @internal Public so the buffering can be tested without a VioContext.
     */
    public function recordKeyEdge(int $key, int $action, float $now): void
    {
        if ($action === 1) {
            $this->keyJustPressed[$key] = $now;
        } elseif ($action === 0) {
            $this->keyJustReleased[$key] = $now;
        } elseif ($action === 2) {
            $this->keyRepeated[$key] = $now;
        }
    }

    /**
     * Drop key edges nobody read within {@see KEY_BUFFER_SECONDS}.
     *
     * @internal Public so the buffering can be tested without a VioContext.
     */
    public function expireKeyEdges(float $now): void
    {
        $cutoff = $now - self::KEY_BUFFER_SECONDS;
        $keep = static fn (float $at): bool => $at >= $cutoff;
        $this->keyJustPressed = array_filter($this->keyJustPressed, $keep);
        $this->keyJustReleased = array_filter($this->keyJustReleased, $keep);
        $this->keyRepeated = array_filter($this->keyRepeated, $keep);
    }

    /**
     * Whether a press of $key is still buffered (unread and not expired).
     *
     * @internal For tests.
     */
    public function hasBufferedPress(int $key): bool
    {
        return isset($this->keyJustPressed[$key]);
    }

    /**
     * A press OR an auto-repeat from holding the key - what TEXT EDITING wants.
     *
     * isKeyPressed() reports the physical press once and nothing more, so a
     * held backspace deletes exactly one character and a held arrow moves the
     * caret once. Editing a line of code then means tapping twenty times.
     * This reports the OS repeat too, at the rate the OS chose, which is the
     * rate every other text field on the machine uses.
     *
     * Deliberately NOT folded into isKeyPressed(): gameplay reads that one, and
     * a held key must not re-trigger a jump or an interaction.
     */
    public function isKeyTyped(int $key): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }

        return $this->consumeTypedEdge($key);
    }

    /**
     * The edge bookkeeping behind {@see isKeyTyped()}, without the context and
     * suppression gates.
     *
     * Split out so it can be tested: VioContext comes from the php-vio
     * extension and cannot be constructed or stubbed in a unit test, which
     * would otherwise leave the interesting half - press and repeat collapsing
     * into ONE typed event, both consumed on read - covered by nothing.
     *
     * @internal
     */
    public function consumeTypedEdge(int $key): bool
    {
        $typed = false;
        if (isset($this->keyJustPressed[$key])) {
            unset($this->keyJustPressed[$key]);
            $typed = true;
        }
        if (isset($this->keyRepeated[$key])) {
            unset($this->keyRepeated[$key]);
            $typed = true;
        }

        return $typed;
    }

    public function isKeyReleased(int $key): bool
    {
        if ($this->ctx === null || $this->isSuppressed()) {
            return false;
        }
        if (isset($this->keyJustReleased[$key])) {
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
        // Consume on read (same semantics as isKeyPressed): the fixed-timestep
        // loop can run several update ticks per rendered frame, but the char
        // buffer is only refilled/cleared once per frame — a non-consuming
        // read would hand the SAME characters to every catch-up tick (typed
        // text doubles whenever the game renders below the tick rate).
        // getCharsTyped() stays non-consuming for the render-phase UI.
        $text = implode('', $this->charBuffer);
        $this->charBuffer = [];
        return $text;
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
     * them on read, and an unread press stays buffered for
     * {@see KEY_BUFFER_SECONDS} – it lets a jump pressed a hair before landing
     * still fire (the controller only reads Space once it's grounded), but not
     * an interact pressed seconds before reaching something.
     */
    public function clearKeyEdges(): void
    {
        $this->keyJustPressed = [];
        $this->keyJustReleased = [];
        $this->keyRepeated = [];
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

        $this->expireKeyEdges(microtime(true));

        if ($this->suppressed) {
            // Suppression DISCARDS input, it does not merely defer it. isKeyPressed()
            // returns false while suppressed WITHOUT consuming the buffered edge, so a
            // key mashed during a suppress window (boot, menu or intro-skip handoff)
            // would otherwise linger in keyJustPressed/keyJustReleased and fire the
            // instant gameplay input resumes — reading as a phantom "held" key that no
            // one is pressing. Drain the edge buffers every suppressed frame so nothing
            // survives the window.
            $this->keyJustPressed = [];
            $this->keyJustReleased = [];

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
