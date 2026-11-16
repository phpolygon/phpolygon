<?php

declare(strict_types=1);

namespace PHPolygon\Testing;

use PHPolygon\Math\Vec2;
use PHPolygon\Runtime\InputInterface;

/**
 * Scriptable {@see InputInterface} for headless tests of game systems and widget
 * trees. Nothing happens until the test says so; every state is a plain setter and
 * {@see endFrame()} clears the one-frame ones (pressed, released, typed, characters,
 * scroll) the way the real input does.
 *
 * Not final: a test can override a method to simulate a quirk.
 */
class ScriptedInput implements InputInterface
{
    /** @var array<int, true> */
    protected array $down = [];
    /** @var array<int, true> */
    protected array $pressed = [];
    /** @var array<int, true> */
    protected array $released = [];
    /** @var array<int, true> */
    protected array $typed = [];
    /** @var array<int, true> */
    protected array $buttonsDown = [];
    /** @var array<int, true> */
    protected array $buttonsPressed = [];
    /** @var array<int, true> */
    protected array $buttonsReleased = [];
    /** @var list<string> */
    protected array $chars = [];

    protected float $mouseX = 0.0;
    protected float $mouseY = 0.0;
    protected float $scrollX = 0.0;
    protected float $scrollY = 0.0;
    protected int $backspaces = 0;
    protected bool $suppressed = false;

    /** The key goes down this frame: down, pressed and typed. */
    public function press(int $key): static
    {
        $this->down[$key] = true;
        $this->pressed[$key] = true;
        $this->typed[$key] = true;

        return $this;
    }

    /** The key comes up this frame: no longer down, released. */
    public function release(int $key): static
    {
        unset($this->down[$key]);
        $this->released[$key] = true;

        return $this;
    }

    /** The key is held without a new press (down only). */
    public function hold(int $key): static
    {
        $this->down[$key] = true;

        return $this;
    }

    /** An auto-repeat of a held key: typed, not pressed. */
    public function repeat(int $key): static
    {
        $this->down[$key] = true;
        $this->typed[$key] = true;

        return $this;
    }

    /** Characters typed this frame, split into UTF-8 characters. */
    public function type(string $text): static
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars !== false) {
            foreach ($chars as $char) {
                $this->chars[] = $char;
            }
        }

        return $this;
    }

    public function setMouse(float $x, float $y): static
    {
        $this->mouseX = $x;
        $this->mouseY = $y;

        return $this;
    }

    public function clickMouse(int $button = 0): static
    {
        $this->buttonsDown[$button] = true;
        $this->buttonsPressed[$button] = true;

        return $this;
    }

    public function releaseMouse(int $button = 0): static
    {
        unset($this->buttonsDown[$button]);
        $this->buttonsReleased[$button] = true;

        return $this;
    }

    public function setScroll(float $x, float $y): static
    {
        $this->scrollX = $x;
        $this->scrollY = $y;

        return $this;
    }

    public function isKeyDown(int $key): bool
    {
        return isset($this->down[$key]);
    }

    public function isKeyPressed(int $key): bool
    {
        return isset($this->pressed[$key]);
    }

    public function isKeyTyped(int $key): bool
    {
        return isset($this->typed[$key]);
    }

    public function isKeyReleased(int $key): bool
    {
        return isset($this->released[$key]);
    }

    public function isMouseButtonDown(int $button): bool
    {
        return isset($this->buttonsDown[$button]);
    }

    public function isMouseButtonPressed(int $button): bool
    {
        return isset($this->buttonsPressed[$button]);
    }

    public function isMouseButtonReleased(int $button): bool
    {
        return isset($this->buttonsReleased[$button]);
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

    public function getCharsTyped(): array
    {
        return $this->chars;
    }

    /** Consumes the buffer like the real input: the first call per frame gets the text. */
    public function getTextInput(): string
    {
        $text = implode('', $this->chars);
        $this->chars = [];

        return $text;
    }

    public function getBackspaceCount(): int
    {
        $count = $this->backspaces;
        $this->backspaces = 0;

        return $count;
    }

    public function showSoftKeyboard(): void {}

    public function hideSoftKeyboard(): void {}

    public function suppress(int $frames = 0, float $seconds = 0.0): void
    {
        $this->suppressed = true;
    }

    public function unsuppress(): void
    {
        $this->suppressed = false;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    public function clearKeyEdges(): void
    {
        $this->pressed = [];
        $this->released = [];
        $this->typed = [];
    }

    public function endFrame(): void
    {
        $this->clearKeyEdges();
        $this->buttonsPressed = [];
        $this->buttonsReleased = [];
        $this->chars = [];
        $this->scrollX = 0.0;
        $this->scrollY = 0.0;
    }
}
