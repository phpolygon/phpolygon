<?php

declare(strict_types=1);

namespace PHPolygon\Math;

class Vec3
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    public static function one(): self
    {
        return new self(1.0, 1.0, 1.0);
    }

    public function add(Vec3 $other): self
    {
        return new self($this->x + $other->x, $this->y + $other->y, $this->z + $other->z);
    }

    public function sub(Vec3 $other): self
    {
        return new self($this->x - $other->x, $this->y - $other->y, $this->z - $other->z);
    }

    public function mul(float $scalar): self
    {
        return new self($this->x * $scalar, $this->y * $scalar, $this->z * $scalar);
    }

    public function div(float $scalar): self
    {
        return new self($this->x / $scalar, $this->y / $scalar, $this->z / $scalar);
    }

    public function length(): float
    {
        return sqrt($this->x * $this->x + $this->y * $this->y + $this->z * $this->z);
    }

    public function normalize(): self
    {
        $len = $this->length();
        if ($len < 1e-10) {
            return self::zero();
        }
        return $this->div($len);
    }

    public function dot(Vec3 $other): float
    {
        return $this->x * $other->x + $this->y * $other->y + $this->z * $other->z;
    }

    public function cross(Vec3 $other): self
    {
        return new self(
            $this->y * $other->z - $this->z * $other->y,
            $this->z * $other->x - $this->x * $other->z,
            $this->x * $other->y - $this->y * $other->x,
        );
    }

    public function lerp(Vec3 $target, float $t): self
    {
        return new self(
            $this->x + ($target->x - $this->x) * $t,
            $this->y + ($target->y - $this->y) * $t,
            $this->z + ($target->z - $this->z) * $t,
        );
    }

    public function equals(Vec3 $other, float $epsilon = 1e-6): bool
    {
        return abs($this->x - $other->x) < $epsilon
            && abs($this->y - $other->y) < $epsilon
            && abs($this->z - $other->z) < $epsilon;
    }

    /** @return array{x: float, y: float, z: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'z' => $this->z];
    }

    /** @param array{x: float, y: float, z: float} $data */
    public static function fromArray(array $data): self
    {
        return new self((float)$data['x'], (float)$data['y'], (float)$data['z']);
    }

    public function __toString(): string
    {
        return "Vec3({$this->x}, {$this->y}, {$this->z})";
    }
}
