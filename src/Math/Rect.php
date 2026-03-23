<?php

declare(strict_types=1);

namespace PHPolygon\Math;

class Rect
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $width = 0.0,
        public float $height = 0.0,
    ) {}

    public function left(): float
    {
        return $this->x;
    }

    public function right(): float
    {
        return $this->x + $this->width;
    }

    public function top(): float
    {
        return $this->y;
    }

    public function bottom(): float
    {
        return $this->y + $this->height;
    }

    public function center(): Vec2
    {
        return new Vec2($this->x + $this->width * 0.5, $this->y + $this->height * 0.5);
    }

    public function size(): Vec2
    {
        return new Vec2($this->width, $this->height);
    }

    public function contains(Vec2 $point): bool
    {
        return $point->x >= $this->x
            && $point->x <= $this->x + $this->width
            && $point->y >= $this->y
            && $point->y <= $this->y + $this->height;
    }

    public function intersects(Rect $other): bool
    {
        return $this->x < $other->x + $other->width
            && $this->x + $this->width > $other->x
            && $this->y < $other->y + $other->height
            && $this->y + $this->height > $other->y;
    }

    public function intersection(Rect $other): ?self
    {
        $x1 = max($this->x, $other->x);
        $y1 = max($this->y, $other->y);
        $x2 = min($this->right(), $other->right());
        $y2 = min($this->bottom(), $other->bottom());

        if ($x2 <= $x1 || $y2 <= $y1) {
            return null;
        }

        return new self($x1, $y1, $x2 - $x1, $y2 - $y1);
    }

    public function expand(float $amount): self
    {
        return new self(
            $this->x - $amount,
            $this->y - $amount,
            $this->width + $amount * 2,
            $this->height + $amount * 2,
        );
    }

    public function equals(Rect $other, float $epsilon = 1e-6): bool
    {
        return abs($this->x - $other->x) < $epsilon
            && abs($this->y - $other->y) < $epsilon
            && abs($this->width - $other->width) < $epsilon
            && abs($this->height - $other->height) < $epsilon;
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }

    /** @param array{x: float, y: float, width: float, height: float} $data */
    public static function fromArray(array $data): self
    {
        return new self((float)$data['x'], (float)$data['y'], (float)$data['width'], (float)$data['height']);
    }
}
