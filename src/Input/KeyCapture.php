<?php

declare(strict_types=1);

namespace PHPolygon\Input;

use PHPolygon\Runtime\InputInterface;

/**
 * "Press a key" for a key-binding menu: reports the first key (or mouse
 * button) that goes down after {@see start()}.
 *
 * Reads only isKeyDown()/isMouseButtonDown(), never isKeyPressed() - the
 * press edge stays buffered for whichever system reads it, so waiting for a
 * key steals nothing. The flip side: if gameplay is still listening while the
 * menu waits, the captured key also reaches it. Gate those systems while
 * capturing, or call InputInterface::clearKeyEdges() once a key was captured.
 *
 * A key already held when capturing starts (the Enter or click that opened
 * the prompt) only counts after it has been released and pressed again.
 * Escape is left out of the default candidates so the game can read it as
 * "cancel".
 *
 * Call {@see poll()} once per frame (update or render, not both).
 */
final class KeyCapture
{
    /** @var list<int> */
    private array $keys = [];

    /** @var list<int> */
    private array $buttons = [];

    /** @var array<int, bool> key => released at least once since start */
    private array $keyArmed = [];

    /** @var array<int, bool> */
    private array $buttonArmed = [];

    private bool $active = false;

    public function __construct(private readonly InputInterface $input) {}

    /**
     * Start waiting.
     *
     * @param list<int>|null $keys         key codes to listen for (null = every GLFW key except Escape)
     * @param list<int>      $mouseButtons mouse buttons to listen for (none by default)
     */
    public function start(?array $keys = null, array $mouseButtons = []): void
    {
        $this->keys = $keys ?? self::defaultKeys();
        $this->buttons = $mouseButtons;
        $this->keyArmed = [];
        $this->buttonArmed = [];
        foreach ($this->keys as $key) {
            $this->keyArmed[$key] = !$this->input->isKeyDown($key);
        }
        foreach ($this->buttons as $button) {
            $this->buttonArmed[$button] = !$this->input->isMouseButtonDown($button);
        }
        $this->active = true;
    }

    public function cancel(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * The captured binding, or null while still waiting (or not active).
     * Capturing ends with the first result.
     */
    public function poll(): ?InputBinding
    {
        if (!$this->active) {
            return null;
        }
        foreach ($this->keys as $key) {
            $down = $this->input->isKeyDown($key);
            if (!$down) {
                $this->keyArmed[$key] = true;
            } elseif ($this->keyArmed[$key] ?? false) {
                $this->active = false;
                return InputBinding::key($key);
            }
        }
        foreach ($this->buttons as $button) {
            $down = $this->input->isMouseButtonDown($button);
            if (!$down) {
                $this->buttonArmed[$button] = true;
            } elseif ($this->buttonArmed[$button] ?? false) {
                $this->active = false;
                return InputBinding::mouseButton($button);
            }
        }
        return null;
    }

    /**
     * Every GLFW key code except Escape.
     *
     * @return list<int>
     */
    public static function defaultKeys(): array
    {
        return array_merge(
            [32, 39],
            range(44, 57),
            [59, 61],
            range(65, 93),
            [96, 161, 162],
            range(257, 269),
            range(280, 284),
            range(290, 314),
            range(320, 336),
            range(340, 348),
        );
    }
}
