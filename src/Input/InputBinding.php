<?php

declare(strict_types=1);

namespace PHPolygon\Input;

enum InputBindingType: string
{
    case Key = 'key';
    case MouseButton = 'mouse_button';
}

class InputBinding
{
    public function __construct(
        public readonly InputBindingType $type,
        public readonly int $code,
    ) {}

    public static function key(int $keyCode): self
    {
        return new self(InputBindingType::Key, $keyCode);
    }

    public static function mouseButton(int $button): self
    {
        return new self(InputBindingType::MouseButton, $button);
    }

    /** Same device and code. */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->code === $other->code;
    }

    /**
     * Plain-array form for saving: ['type' => 'key', 'code' => 71].
     *
     * @return array{type: string, code: int}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'code' => $this->code];
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

        return $type === null ? null : new self($type, $data['code']);
    }
}
