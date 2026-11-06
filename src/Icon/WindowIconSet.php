<?php

declare(strict_types=1);

namespace PHPolygon\Icon;

/**
 * The window icon in every size the platforms ask for, ready for
 * `vio_set_window_icon()`. A build writes it once from build.json `icon`
 * ({@see self::FILE} under resources/), so a start only inflates 361 KB of
 * RGBA (all seven sizes) - no PNG decoding, no resampling.
 *
 * File format (zlib-compressed): "PPWICON1", u16 count, then per image u16
 * width, u16 height (big endian) and width x height x 4 bytes RGBA.
 */
final class WindowIconSet
{
    /** File name below the game's resources directory. */
    public const FILE = 'phpolygon-window-icon.bin';

    /**
     * Title bar, taskbar and Alt-Tab at 100-200 % scaling (Windows picks the
     * nearest size to SM_CXICON / SM_CXSMICON), X11 _NET_WM_ICON, the Dock.
     */
    public const SIZES = [16, 24, 32, 48, 64, 128, 256];

    private const MAGIC = 'PPWICON1';

    /**
     * @param list<RgbaImage> $images
     */
    public function __construct(public readonly array $images)
    {
        if ($images === []) {
            throw new \InvalidArgumentException('A window icon needs at least one image');
        }
    }

    /**
     * Every size of {@see self::SIZES} up to the source's size, from one square
     * image (area averaging, {@see IconResampler}). $small, when given, makes
     * the sizes up to min(32, its size).
     */
    public static function fromImage(RgbaImage $source, ?RgbaImage $small = null): self
    {
        $images = IconResampler::sizes($source, self::SIZES);
        if ($small !== null) {
            $smallSizes = array_values(array_filter(self::SIZES, static fn (int $s): bool => $s <= min(32, $small->width)));
            $images = IconResampler::sizes($small, $smallSizes) + $images;
        }
        ksort($images);
        return new self(array_values($images));
    }

    public function encode(): string
    {
        $out = self::MAGIC . pack('n', count($this->images));
        foreach ($this->images as $image) {
            $out .= pack('nn', $image->width, $image->height) . $image->pixels;
        }
        return (string) gzcompress($out, 9);
    }

    public static function decode(string $bytes): self
    {
        $raw = @gzuncompress($bytes);
        if ($raw === false || !str_starts_with($raw, self::MAGIC) || strlen($raw) < 10) {
            throw new \InvalidArgumentException('Not a window icon set');
        }
        /** @var array{1: int} $count */
        $count = unpack('n', $raw, 8);
        $images = [];
        $pos = 10;
        for ($i = 0; $i < $count[1]; $i++) {
            if ($pos + 4 > strlen($raw)) {
                throw new \InvalidArgumentException('Window icon set is cut off');
            }
            /** @var array{1: int, 2: int} $size */
            $size = unpack('n2', $raw, $pos);
            $length = $size[1] * $size[2] * 4;
            $images[] = new RgbaImage($size[1], $size[2], substr($raw, $pos + 4, $length));
            $pos += 4 + $length;
        }
        return new self($images);
    }

    /**
     * The argument of `vio_set_window_icon()`.
     *
     * @return list<array{data: string, width: int, height: int}>
     */
    public function toVio(): array
    {
        return array_map(
            static fn (RgbaImage $image): array => ['data' => $image->pixels, 'width' => $image->width, 'height' => $image->height],
            $this->images,
        );
    }
}
