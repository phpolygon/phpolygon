<?php

declare(strict_types=1);

namespace PHPolygon\Input;

class InputAction
{
    private bool $pressed = false;
    private bool $held = false;
    private bool $released = false;
    private float $value = 0.0;

    public function __construct(
        public readonly string $name,
    ) {}

    public function isPressed(): bool
    {
        return $this->pressed;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    /** @internal */
    public function update(bool $down): void
    {
        $this->pressed = $down && !$this->held;
        $this->released = !$down && $this->held;
        $this->held = $down;
        $this->value = $down ? 1.0 : 0.0;
    }
}
