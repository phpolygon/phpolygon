<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Maps a character to the key that types it in the current keyboard layout.
 *
 * Key codes name physical positions after the US layout (GLFW semantics):
 * on a German keyboard the key code for 'Y' sits under the keycap 'Z'.
 * Shortcuts named by a letter (Ctrl+Z, Ctrl+C) look their key up here so
 * they follow the keycaps.
 */
interface KeyboardLayoutInterface
{
    /** Key code that types $char (a letter or digit, case-insensitive), -1 when none does. */
    public function keyFor(string $char): int;
}
