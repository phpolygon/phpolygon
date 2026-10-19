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

    /**
     * A colour written as an sRGB hex – the way a designer picks it, "how it
     * should look on screen" – converted to LINEAR for use as a material
     * albedo.
     *
     * The lit shaders take albedo as linear and gamma-encode the result, so a
     * plain {@see hex()} albedo comes out lifted and, after the tonemap,
     * washed out (#A85A30 terracotta reads as a pale skin tone). Use this for
     * surface colours authored as hex; keep {@see hex()} for values that are
     * already linear, for emission/HDR intensities and for UI colours, which
     * are drawn as they are. Alpha is not converted.
     */
    public static function srgb(string $hex): self
    {
        return self::hex($hex)->toLinear();
    }

    /** Decode sRGB-encoded RGB channels to linear (the exact sRGB curve); alpha unchanged. */
    public function toLinear(): self
    {
        return new self(self::srgbToLinear($this->r), self::srgbToLinear($this->g), self::srgbToLinear($this->b), $this->a);
    }

    /** Encode linear RGB channels as sRGB (inverse of {@see toLinear()}); alpha unchanged. */
    public function toSrgb(): self
    {
        return new self(self::linearToSrgb($this->r), self::linearToSrgb($this->g), self::linearToSrgb($this->b), $this->a);
    }

    private static function srgbToLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function linearToSrgb(float $c): float
    {
        return $c <= 0.0031308 ? $c * 12.92 : 1.055 * ($c ** (1.0 / 2.4)) - 0.055;
    }

    public static function rgba(int $r, int $g, int $b, int $a = 255): self
    {
        return new self($r / 255.0, $g / 255.0, $b / 255.0, $a / 255.0);
    }

    public function withAlpha(float $alpha): self
    {
        return new self($this->r, $this->g, $this->b, $alpha);
    }

    /** Lighten each RGB channel by an additive amount (clamped to 1.0). */
    public function lighten(float $amount): self
    {
        return new self(
            min(1.0, $this->r + $amount),
            min(1.0, $this->g + $amount),
            min(1.0, $this->b + $amount),
            $this->a,
        );
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
