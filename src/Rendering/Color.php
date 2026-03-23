<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

class Color
{
    public function __construct(
        public float $r = 1.0,
        public float $g = 1.0,
        public float $b = 1.0,
        public float $a = 1.0,
    ) {}

    public static function white(): self
    {
        return new self(1.0, 1.0, 1.0, 1.0);
    }

    public static function black(): self
    {
        return new self(0.0, 0.0, 0.0, 1.0);
    }

    public static function red(): self
    {
        return new self(1.0, 0.0, 0.0, 1.0);
    }

    public static function green(): self
    {
        return new self(0.0, 1.0, 0.0, 1.0);
    }

    public static function blue(): self
    {
        return new self(0.0, 0.0, 1.0, 1.0);
    }

    public static function transparent(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }

    public static function hex(string $hex): self
    {
        $hex = ltrim($hex, '#');
        $len = strlen($hex);

        if ($len === 3 || $len === 4) {
            $hex = preg_replace('/(.)/', '$1$1', $hex);
            /** @var string $hex */
            $len = strlen($hex);
        }

        $r = hexdec(substr($hex, 0, 2)) / 255.0;
        $g = hexdec(substr($hex, 2, 2)) / 255.0;
        $b = hexdec(substr($hex, 4, 2)) / 255.0;
        $a = $len === 8 ? hexdec(substr($hex, 6, 2)) / 255.0 : 1.0;

        return new self((float)$r, (float)$g, (float)$b, (float)$a);
    }

    public static function rgba(int $r, int $g, int $b, int $a = 255): self
    {
        return new self($r / 255.0, $g / 255.0, $b / 255.0, $a / 255.0);
    }

    public function withAlpha(float $alpha): self
    {
        return new self($this->r, $this->g, $this->b, $alpha);
    }

    public function lerp(Color $target, float $t): self
    {
        return new self(
            $this->r + ($target->r - $this->r) * $t,
            $this->g + ($target->g - $this->g) * $t,
            $this->b + ($target->b - $this->b) * $t,
            $this->a + ($target->a - $this->a) * $t,
        );
    }

    /** @return array{r: float, g: float, b: float, a: float} */
    public function toArray(): array
    {
        return ['r' => $this->r, 'g' => $this->g, 'b' => $this->b, 'a' => $this->a];
    }

    /** @param array{r: float, g: float, b: float, a: float} $data */
    public static function fromArray(array $data): self
    {
        return new self((float)$data['r'], (float)$data['g'], (float)$data['b'], (float)$data['a']);
    }
}
