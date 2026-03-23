<?php

declare(strict_types=1);

namespace PHPolygon\Math;

class Vec2
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0);
    }

    public static function one(): self
    {
        return new self(1.0, 1.0);
    }

    public static function up(): self
    {
        return new self(0.0, -1.0);
    }

    public static function down(): self
    {
        return new self(0.0, 1.0);
    }

    public static function left(): self
    {
        return new self(-1.0, 0.0);
    }

    public static function right(): self
    {
        return new self(1.0, 0.0);
    }

    public function add(Vec2 $other): self
    {
        return new self($this->x + $other->x, $this->y + $other->y);
    }

    public function sub(Vec2 $other): self
    {
        return new self($this->x - $other->x, $this->y - $other->y);
    }

    public function mul(float $scalar): self
    {
        return new self($this->x * $scalar, $this->y * $scalar);
    }

    public function div(float $scalar): self
    {
        return new self($this->x / $scalar, $this->y / $scalar);
    }

    public function mulVec(Vec2 $other): self
    {
        return new self($this->x * $other->x, $this->y * $other->y);
    }

    public function length(): float
    {
        return sqrt($this->x * $this->x + $this->y * $this->y);
    }

    public function lengthSquared(): float
    {
        return $this->x * $this->x + $this->y * $this->y;
    }

    public function normalize(): self
    {
        $len = $this->length();
        if ($len < 1e-10) {
            return self::zero();
        }
        return $this->div($len);
    }

    public function dot(Vec2 $other): float
    {
        return $this->x * $other->x + $this->y * $other->y;
    }

    public function cross(Vec2 $other): float
    {
        return $this->x * $other->y - $this->y * $other->x;
    }

    public function lerp(Vec2 $target, float $t): self
    {
        return new self(
            $this->x + ($target->x - $this->x) * $t,
            $this->y + ($target->y - $this->y) * $t,
        );
    }

    public function distance(Vec2 $other): float
    {
        return $this->sub($other)->length();
    }

    public function distanceSquared(Vec2 $other): float
    {
        return $this->sub($other)->lengthSquared();
    }

    public function negate(): self
    {
        return new self(-$this->x, -$this->y);
    }

    public function angle(): float
    {
        return atan2($this->y, $this->x);
    }

    public function rotate(float $radians): self
    {
        $cos = cos($radians);
        $sin = sin($radians);
        return new self(
            $this->x * $cos - $this->y * $sin,
            $this->x * $sin + $this->y * $cos,
        );
    }

    public function equals(Vec2 $other, float $epsilon = 1e-6): bool
    {
        return abs($this->x - $other->x) < $epsilon
            && abs($this->y - $other->y) < $epsilon;
    }

    /** @return array{x: float, y: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }

    /** @param array{x: float, y: float} $data */
    public static function fromArray(array $data): self
    {
        return new self((float)$data['x'], (float)$data['y']);
    }

    public function __toString(): string
    {
        return "Vec2({$this->x}, {$this->y})";
    }
}
