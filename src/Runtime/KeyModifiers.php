<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Which modifier keys are held, and what they mean on this platform.
 *
 * Read from the key state ({@see InputInterface::isKeyDown()}, left and right
 * key of each pair), so every input implementation provides it. The primary
 * shortcut modifier is Command on macOS and Control elsewhere; word-wise
 * caret movement uses Option on macOS and Control elsewhere.
 */
final readonly class KeyModifiers
{
    public const int KEY_LEFT_SHIFT = 340;
    public const int KEY_LEFT_CONTROL = 341;
    public const int KEY_LEFT_ALT = 342;
    public const int KEY_LEFT_SUPER = 343;
    public const int KEY_RIGHT_SHIFT = 344;
    public const int KEY_RIGHT_CONTROL = 345;
    public const int KEY_RIGHT_ALT = 346;
    public const int KEY_RIGHT_SUPER = 347;

    public bool $mac;

    public function __construct(
        public bool $shift = false,
        public bool $control = false,
        public bool $alt = false,
        public bool $super = false,
        ?bool $mac = null,
    ) {
        $this->mac = $mac ?? PHP_OS_FAMILY === 'Darwin';
    }

    public static function fromInput(InputInterface $input, ?bool $mac = null): self
    {
        return new self(
            shift: $input->isKeyDown(self::KEY_LEFT_SHIFT) || $input->isKeyDown(self::KEY_RIGHT_SHIFT),
            control: $input->isKeyDown(self::KEY_LEFT_CONTROL) || $input->isKeyDown(self::KEY_RIGHT_CONTROL),
            alt: $input->isKeyDown(self::KEY_LEFT_ALT) || $input->isKeyDown(self::KEY_RIGHT_ALT),
            super: $input->isKeyDown(self::KEY_LEFT_SUPER) || $input->isKeyDown(self::KEY_RIGHT_SUPER),
            mac: $mac,
        );
    }

    public function any(): bool
    {
        return $this->shift || $this->control || $this->alt || $this->super;
    }

    /** Command on macOS, Control elsewhere. */
    public function primary(): bool
    {
        return $this->mac ? $this->super : $this->control;
    }

    /**
     * The primary modifier without Alt: a shortcut such as copy or undo.
     * Control + Alt is AltGr on Windows layouts and types characters
     * ('@', '{', ...), so it never counts as a shortcut.
     */
    public function shortcut(): bool
    {
        return $this->primary() && !$this->alt;
    }

    /** Word-wise caret movement and deletion: Option on macOS, Control elsewhere. */
    public function wordJump(): bool
    {
        return $this->mac ? $this->alt : $this->control;
    }
}
