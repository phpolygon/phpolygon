<?php

declare(strict_types=1);

namespace PHPolygon\Icon;

/**
 * An 8-bit RGBA image: rows top to bottom, four bytes per pixel, straight
 * (not premultiplied) alpha. The pixel layout php-vio takes for window,
 * cursor and tray icons.
 */
final class RgbaImage
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $pixels,
    ) {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException("Image size must be positive, got {$width}x{$height}");
        }
        if (strlen($pixels) !== $width * $height * 4) {
            throw new \InvalidArgumentException(sprintf(
                'A %dx%d RGBA image needs %d bytes, got %d',
                $width,
                $height,
                $width * $height * 4,
                strlen($pixels),
            ));
        }
    }

    /**
     * One pixel as [r, g, b, a].
     *
     * @return array{int, int, int, int}
     */
    public function pixel(int $x, int $y): array
    {
        $o = ($y * $this->width + $x) * 4;
        return [ord($this->pixels[$o]), ord($this->pixels[$o + 1]), ord($this->pixels[$o + 2]), ord($this->pixels[$o + 3])];
    }

    public function isSquare(): bool
    {
        return $this->width === $this->height;
    }
}
