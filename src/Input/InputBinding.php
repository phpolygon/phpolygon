<?php

declare(strict_types=1);

namespace PHPolygon\Input;

use PHPolygon\Runtime\KeyModifiers;

enum InputBindingType: string
{
    case Key = 'key';
    case MouseButton = 'mouse_button';
}

class InputBinding
{
    /** Modifier bits for {@see $modifiers}. */
    public const int MOD_SHIFT = 1;
    public const int MOD_CONTROL = 2;
    public const int MOD_ALT = 4;
    public const int MOD_SUPER = 8;

    /**
     * @param int $modifiers MOD_* bits that must be held together with the key
     *        (Shift+Tab). 0, the default, ignores modifiers like it always did.
     */
    public function __construct(
        public readonly InputBindingType $type,
        public readonly int $code,
        public readonly int $modifiers = 0,
    ) {}

    public static function key(int $keyCode, int $modifiers = 0): self
    {
        return new self(InputBindingType::Key, $keyCode, $modifiers);
    }

    public static function mouseButton(int $button): self
    {
        return new self(InputBindingType::MouseButton, $button);
    }

    /** Same device, code and modifiers. */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->code === $other->code && $this->modifiers === $other->modifiers;
    }

    /** Whether the held modifiers satisfy this binding (always true without any). */
    public function modifiersHeld(KeyModifiers $held): bool
    {
        return (($this->modifiers & self::MOD_SHIFT) === 0 || $held->shift)
            && (($this->modifiers & self::MOD_CONTROL) === 0 || $held->control)
            && (($this->modifiers & self::MOD_ALT) === 0 || $held->alt)
            && (($this->modifiers & self::MOD_SUPER) === 0 || $held->super);
    }

    /**
     * Plain-array form for saving: ['type' => 'key', 'code' => 71]; a binding with
     * modifiers adds 'modifiers' (older saves have none and load unchanged).
     *
     * @return array{type: string, code: int, modifiers?: int}
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type->value, 'code' => $this->code];
        if ($this->modifiers !== 0) {
            $data['modifiers'] = $this->modifiers;
        }

        return $data;
    }

    /**
     * Inverse of {@see toArray()}. A bare int is read as a key code, so a
     * setting that only ever stored one key per action still loads. Returns
     * null for anything else.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (is_int($data)) {
            return self::key($data);
        }
        if (!is_array($data) || !isset($data['type'], $data['code']) || !is_int($data['code'])) {
            return null;
        }
        $type = is_string($data['type']) ? InputBindingType::tryFrom($data['type']) : null;

        $modifiers = isset($data['modifiers']) && is_int($data['modifiers']) ? $data['modifiers'] & 15 : 0;

        return $type === null ? null : new self($type, $data['code'], $modifiers);
    }
}
