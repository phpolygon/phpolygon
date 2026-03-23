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
}
