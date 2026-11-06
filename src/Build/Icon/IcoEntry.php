<?php

declare(strict_types=1);

namespace PHPolygon\Build\Icon;

/**
 * One image of a Windows icon: the fields of its ICONDIRENTRY / GRPICONDIRENTRY
 * and the image bytes (a PNG, or a DIB without file header: BITMAPINFOHEADER,
 * XOR bitmap, AND mask). The same bytes go into a .ico and into an RT_ICON
 * resource.
 */
final class IcoEntry
{
    public function __construct(
        /** Pixels, 1..256 (stored as 0 for 256). */
        public readonly int $width,
        public readonly int $height,
        public readonly int $colorCount,
        public readonly int $planes,
        public readonly int $bitCount,
        public readonly string $data,
    ) {}

    public function isPng(): bool
    {
        return str_starts_with($this->data, "\x89PNG\r\n\x1a\n");
    }

    /** The first eight bytes both directory entry layouts share. */
    public function directoryHead(): string
    {
        return pack(
            'CCCCvv',
            $this->width >= 256 ? 0 : $this->width,
            $this->height >= 256 ? 0 : $this->height,
            $this->colorCount,
            0,
            $this->planes,
            $this->bitCount,
        );
    }
}
