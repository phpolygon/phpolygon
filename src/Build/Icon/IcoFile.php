<?php

declare(strict_types=1);

namespace PHPolygon\Build\Icon;

use PHPolygon\Icon\Png;
use PHPolygon\Icon\RgbaImage;

/**
 * Windows .ico files: written from RGBA images, read back into entries (for a
 * game's own .ico and the tests).
 *
 * Images below 256 px are stored as 32-bit DIBs (BGRA bottom-up plus a 1-bit AND
 * mask that marks fully transparent pixels): every icon reader since Windows XP
 * takes them. 256 px is stored as PNG, as Windows Vista introduced and the
 * resource compiler keeps it.
 */
final class IcoFile
{
    /** The sizes a Windows icon carries: Explorer views, taskbar, title bar at 100-200 % scaling. */
    public const SIZES = [16, 24, 32, 48, 64, 128, 256];

    /** Images from this size up are stored as PNG. */
    public const PNG_FROM = 256;

    /**
     * Entries for $images (any order; written smallest first).
     *
     * @param array<int, RgbaImage> $images
     * @return list<IcoEntry>
     */
    public static function entries(array $images): array
    {
        usort($images, static fn (RgbaImage $a, RgbaImage $b): int => $a->width <=> $b->width);
        $entries = [];
        foreach ($images as $image) {
            if ($image->width > 256 || $image->height > 256) {
                throw new \InvalidArgumentException("Windows icons hold at most 256x256, got {$image->width}x{$image->height}");
            }
            $data = $image->width >= self::PNG_FROM ? Png::encode($image) : self::dib($image);
            $entries[] = new IcoEntry($image->width, $image->height, 0, 1, 32, $data);
        }
        return $entries;
    }

    /**
     * @param list<IcoEntry> $entries
     */
    public static function build(array $entries): string
    {
        $count = count($entries);
        if ($count === 0 || $count > 0xffff) {
            throw new \InvalidArgumentException('An icon needs 1..65535 images');
        }
        $out = pack('vvv', 0, 1, $count);
        $offset = 6 + 16 * $count;
        $data = '';
        foreach ($entries as $entry) {
            $out .= $entry->directoryHead() . pack('VV', strlen($entry->data), $offset);
            $offset += strlen($entry->data);
            $data .= $entry->data;
        }
        return $out . $data;
    }

    /**
     * @return list<IcoEntry>
     */
    public static function parse(string $ico): array
    {
        if (strlen($ico) < 6) {
            throw new \InvalidArgumentException('Not an icon file: too short');
        }
        /** @var array{reserved: int, type: int, count: int} $head */
        $head = unpack('vreserved/vtype/vcount', $ico);
        if ($head['reserved'] !== 0 || $head['type'] !== 1 || $head['count'] < 1) {
            throw new \InvalidArgumentException('Not an icon file (.ico header)');
        }
        if (strlen($ico) < 6 + 16 * $head['count']) {
            throw new \InvalidArgumentException('Icon directory is cut off');
        }
        $entries = [];
        for ($i = 0; $i < $head['count']; $i++) {
            /** @var array{w: int, h: int, colors: int, reserved: int, planes: int, bits: int, size: int, offset: int} $e */
            $e = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', $ico, 6 + 16 * $i);
            if ($e['offset'] + $e['size'] > strlen($ico) || $e['size'] === 0) {
                throw new \InvalidArgumentException("Icon image {$i} lies outside the file");
            }
            $entries[] = new IcoEntry(
                $e['w'] === 0 ? 256 : $e['w'],
                $e['h'] === 0 ? 256 : $e['h'],
                $e['colors'],
                $e['planes'],
                $e['bits'],
                substr($ico, $e['offset'], $e['size']),
            );
        }
        return $entries;
    }

    /**
     * A 32-bit DIB as stored in icons: BITMAPINFOHEADER with the doubled
     * height (XOR + AND), BGRA rows bottom-up, then the AND mask (1 = transparent),
     * each mask row padded to 32 bits.
     */
    public static function dib(RgbaImage $image): string
    {
        $w = $image->width;
        $h = $image->height;
        $maskStride = intdiv($w + 31, 32) * 4;
        $header = pack('VVVvvVVVVVV', 40, $w, $h * 2, 1, 32, 0, $w * $h * 4 + $maskStride * $h, 0, 0, 0, 0);
        $xor = '';
        $and = '';
        for ($y = $h - 1; $y >= 0; $y--) {
            $row = substr($image->pixels, $y * $w * 4, $w * 4);
            // RGBA -> BGRA: swap bytes 0 and 2 of every pixel.
            $xor .= (string) preg_replace('/(.)(.)(.)(.)/s', '$3$2$1$4', $row);
            $bits = array_fill(0, $maskStride, 0);
            for ($x = 0; $x < $w; $x++) {
                if ($row[$x * 4 + 3] === "\0") {
                    $bits[$x >> 3] |= 0x80 >> ($x & 7);
                }
            }
            $and .= pack('C*', ...$bits);
        }
        return $header . $xor . $and;
    }
}
