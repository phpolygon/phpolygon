<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Math\Vec2;

/**
 * InputInterface — read/query contract for the engine input system.
 *
 * Game systems depend on this interface rather than the concrete Input class,
 * keeping them decoupled from the runtime implementation and easily testable.
 *
 * endFrame() is part of the public contract because it is called by the Engine
 * game-loop at the end of every render pass. Systems must not call it themselves.
 */
interface InputInterface
{
    // ---- Keyboard ----

    /** True every frame the key is held down. */
    public function isKeyDown(int $key): bool;

    /** True only on the first frame the key is pressed (edge detection). */
    public function isKeyPressed(int $key): bool;

    /** True only on the first frame the key is released (edge detection). */
    public function isKeyReleased(int $key): bool;

    // ---- Mouse buttons ----

    public function isMouseButtonDown(int $button): bool;

    public function isMouseButtonPressed(int $button): bool;

    public function isMouseButtonReleased(int $button): bool;

    // ---- Mouse position & scroll ----

    public function getMousePosition(): Vec2;

    public function getMouseX(): float;

    public function getMouseY(): float;

    public function getScrollX(): float;

    public function getScrollY(): float;

    // ---- Text input ----

    /** @return list<string> UTF-8 characters typed this frame. */
    public function getCharsTyped(): array;

    /** All characters typed this frame as a single concatenated string. */
    public function getTextInput(): string;

    /**
     * Number of backspaces from an on-screen keyboard since the last call.
     * Non-zero only on touch platforms (iOS); on desktop physical Backspace
     * flows through the key API and this returns 0.
     */
    public function getBackspaceCount(): int;

    /**
     * Show / hide the on-screen keyboard. No-op on desktop (physical keyboard).
     * On touch platforms (iOS) the engine calls these when a text field gains
     * or loses focus so the soft keyboard appears.
     */
    public function showSoftKeyboard(): void;

    public function hideSoftKeyboard(): void;

    // ---- Suppression ----

    /**
     * Suppress game key/mouse events (e.g. while a UI overlay is active).
     * Char events still pass through.
     *
     * @param int   $frames  Frames to suppress (0 = until unsuppress())
     * @param float $seconds Additional time-based suppression
     */
    public function suppress(int $frames = 0, float $seconds = 0.0): void;

    public function unsuppress(): void;

    public function isSuppressed(): bool;

    /**
     * Drop buffered "just pressed/released" key edges that no system consumed.
     * Call when returning to gameplay from a modal so a key typed into the
     * modal can't fire as a buffered action the moment it closes.
     */
    public function clearKeyEdges(): void;

    // ---- Engine lifecycle (called by Engine::run, not by game systems) ----

    /** Advance frame state: snapshot pressed→prev, clear scroll/chars. */
    public function endFrame(): void;
}
