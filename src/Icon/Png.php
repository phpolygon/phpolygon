<?php

declare(strict_types=1);

namespace PHPolygon\Icon;

/**
 * PNG reader and writer in plain PHP (zlib only, no GD), so icons are made the
 * same way on every build host - a container without GD included.
 *
 * Reads every non-interlaced PNG: grey, grey + alpha, RGB, RGBA and palette,
 * bit depths 1 to 16, palette transparency (tRNS) and the colour key of grey
 * and RGB images. 16-bit samples keep their high byte. Colour management
 * chunks (gAMA, iCCP, sRGB) are ignored: icons are taken as sRGB. Writes 8-bit
 * RGBA with one filter per row (smallest sum of absolute differences, the
 * heuristic libpng uses) and zlib level 9.
 */
final class Png
{
    public const SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    /** Is $bytes a PNG (by its signature)? */
    public static function isPng(string $bytes): bool
    {
        return str_starts_with($bytes, self::SIGNATURE);
    }

    /**
     * Width and height from the IHDR chunk, without decoding the image.
     *
     * @return array{int, int}
     */
    public static function size(string $png): array
    {
        if (!self::isPng($png) || strlen($png) < 24 || substr($png, 12, 4) !== 'IHDR') {
            throw new \InvalidArgumentException('Not a PNG file');
        }
        /** @var array{1: int, 2: int} $wh */
        $wh = unpack('N2', $png, 16);
        return [$wh[1], $wh[2]];
    }

    public static function decodeFile(string $path): RgbaImage
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read image: {$path}");
        }
        try {
            return self::decode($bytes);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($path . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public static function decode(string $png): RgbaImage
    {
        if (!self::isPng($png)) {
            throw new \InvalidArgumentException('Not a PNG file');
        }
        $length = strlen($png);
        $pos = 8;
        $header = null;
        $palette = '';
        $trns = null;
        $idat = '';
        while ($pos + 12 <= $length) {
            /** @var array{1: int} $len */
            $len = unpack('N', $png, $pos);
            $chunkLength = $len[1];
            $type = substr($png, $pos + 4, 4);
            if ($pos + 12 + $chunkLength > $length) {
                throw new \InvalidArgumentException("PNG chunk {$type} is cut off");
            }
            $data = substr($png, $pos + 8, $chunkLength);
            /** @var array{1: int} $crc */
            $crc = unpack('N', $png, $pos + 8 + $chunkLength);
            if (crc32($type . $data) !== $crc[1]) {
                throw new \InvalidArgumentException("PNG chunk {$type} has a bad CRC");
            }
            $pos += 12 + $chunkLength;
            if ($type === 'IHDR') {
                if ($chunkLength !== 13) {
                    throw new \InvalidArgumentException('PNG header has the wrong size');
                }
                /** @var array{width: int, height: int, depth: int, color: int, compression: int, filter: int, interlace: int} $header */
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
            } elseif ($type === 'PLTE') {
                $palette = $data;
            } elseif ($type === 'tRNS') {
                $trns = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if ($header === null) {
            throw new \InvalidArgumentException('PNG has no header');
        }
        $width = $header['width'];
        $height = $header['height'];
        $depth = $header['depth'];
        $color = $header['color'];
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException("PNG size {$width}x{$height} is empty");
        }
        if (!isset(self::CHANNELS[$color])) {
            throw new \InvalidArgumentException("PNG colour type {$color} is unknown");
        }
        $allowedDepths = match ($color) {
            0 => [1, 2, 4, 8, 16],
            3 => [1, 2, 4, 8],
            default => [8, 16],
        };
        if (!in_array($depth, $allowedDepths, true)) {
            throw new \InvalidArgumentException("PNG bit depth {$depth} is invalid for colour type {$color}");
        }
        if ($header['compression'] !== 0 || $header['filter'] !== 0) {
            throw new \InvalidArgumentException('PNG uses an unknown compression or filter method');
        }
        if ($header['interlace'] !== 0) {
            throw new \InvalidArgumentException('Interlaced PNG files are not supported; save the image without interlacing');
        }
        if ($color === 3 && $palette === '') {
            throw new \InvalidArgumentException('Palette PNG without a palette');
        }

        $raw = @gzuncompress($idat);
        if ($raw === false) {
            throw new \InvalidArgumentException('PNG image data does not inflate');
        }
        $channels = self::CHANNELS[$color];
        $bitsPerPixel = $channels * $depth;
        $bpp = max(1, intdiv($bitsPerPixel, 8));
        $stride = intdiv($width * $bitsPerPixel + 7, 8);
        if (strlen($raw) < $height * ($stride + 1)) {
            throw new \InvalidArgumentException('PNG image data is too short');
        }

        $toRgba = self::rowConverter($width, $depth, $color, $palette, $trns);
        $prev = array_fill(1, $stride, 0);
        $rows = [];
        for ($y = 0, $off = 0; $y < $height; $y++, $off += $stride + 1) {
            $filter = ord($raw[$off]);
            /** @var array<int, int> $line */
            $line = unpack('C*', substr($raw, $off + 1, $stride));
            switch ($filter) {
                case 0:
                    break;
                case 1:
                    for ($i = $bpp + 1; $i <= $stride; $i++) {
                        $line[$i] = ($line[$i] + $line[$i - $bpp]) & 0xff;
                    }
                    break;
                case 2:
                    for ($i = 1; $i <= $stride; $i++) {
                        $line[$i] = ($line[$i] + $prev[$i]) & 0xff;
                    }
                    break;
                case 3:
                    for ($i = 1; $i <= $bpp; $i++) {
                        $line[$i] = ($line[$i] + ($prev[$i] >> 1)) & 0xff;
                    }
                    for ($i = $bpp + 1; $i <= $stride; $i++) {
                        $line[$i] = ($line[$i] + (($line[$i - $bpp] + $prev[$i]) >> 1)) & 0xff;
                    }
                    break;
                case 4:
                    for ($i = 1; $i <= $bpp; $i++) {
                        $line[$i] = ($line[$i] + $prev[$i]) & 0xff;
                    }
                    for ($i = $bpp + 1; $i <= $stride; $i++) {
                        $a = $line[$i - $bpp];
                        $b = $prev[$i];
                        $c = $prev[$i - $bpp];
                        $pa = abs($b - $c);
                        $pb = abs($a - $c);
                        $pc = abs($a + $b - 2 * $c);
                        $line[$i] = ($line[$i] + ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c))) & 0xff;
                    }
                    break;
                default:
                    throw new \InvalidArgumentException("PNG row {$y} has the unknown filter {$filter}");
            }
            $rows[] = $toRgba($line);
            $prev = $line;
        }

        return new RgbaImage($width, $height, implode('', $rows));
    }

    /**
     * Turns one unfiltered row (1-based bytes) into RGBA bytes.
     *
     * @return \Closure(array<int, int>): string
     */
    private static function rowConverter(int $width, int $depth, int $color, string $palette, ?string $trns): \Closure
    {
        if ($color === 6 && $depth === 8) {
            return static fn (array $line): string => pack('C*', ...$line);
        }
        if ($color === 2 && $depth === 8 && $trns === null) {
            return static function (array $line) use ($width): string {
                $out = '';
                $hex = bin2hex(pack('C*', ...$line));
                for ($x = 0; $x < $width; $x++) {
                    $out .= substr($hex, $x * 6, 6) . 'ff';
                }
                return (string) hex2bin($out);
            };
        }

        // General path: samples first, then the colour type's mapping.
        $channels = self::CHANNELS[$color];
        $max = (1 << $depth) - 1;
        $key = null;
        if ($trns !== null && $color === 0 && strlen($trns) >= 2) {
            /** @var array{1: int} $k */
            $k = unpack('n', $trns);
            $key = [$k[1]];
        } elseif ($trns !== null && $color === 2 && strlen($trns) >= 6) {
            /** @var array{1: int, 2: int, 3: int} $k */
            $k = unpack('n3', $trns);
            $key = [$k[1], $k[2], $k[3]];
        }
        $paletteRgba = [];
        if ($color === 3) {
            $entries = intdiv(strlen($palette), 3);
            for ($i = 0; $i < $entries; $i++) {
                $alpha = $trns !== null && $i < strlen($trns) ? $trns[$i] : "\xff";
                $paletteRgba[$i] = substr($palette, $i * 3, 3) . $alpha;
            }
        }

        return static function (array $line) use ($width, $depth, $color, $channels, $max, $key, $paletteRgba): string {
            /** @var array<int, int> $line */
            /** @var list<int> $samples */
            $samples = [];
            if ($depth === 8) {
                $samples = array_values($line);
            } elseif ($depth === 16) {
                $count = count($line);
                for ($i = 1; $i <= $count; $i += 2) {
                    $samples[] = ($line[$i] << 8) | $line[$i + 1];
                }
            } else {
                $perByte = intdiv(8, $depth);
                $needed = $width * $channels;
                foreach ($line as $byte) {
                    for ($s = $perByte - 1; $s >= 0 && count($samples) < $needed; $s--) {
                        $samples[] = ($byte >> ($s * $depth)) & $max;
                    }
                }
            }
            $out = '';
            for ($x = 0; $x < $width; $x++) {
                $base = $x * $channels;
                if ($color === 3) {
                    $out .= $paletteRgba[$samples[$base]] ?? "\0\0\0\xff";
                    continue;
                }
                $px = array_slice($samples, $base, $channels);
                $alpha = 255;
                if ($key !== null && $px === $key) {
                    $alpha = 0;
                }
                $to8 = static fn (int $v): int => $depth === 16 ? $v >> 8 : intdiv($v * 255, $max);
                $out .= match ($color) {
                    0 => pack('C4', $to8($px[0]), $to8($px[0]), $to8($px[0]), $alpha),
                    4 => pack('C4', $to8($px[0]), $to8($px[0]), $to8($px[0]), $to8($px[1])),
                    2 => pack('C4', $to8($px[0]), $to8($px[1]), $to8($px[2]), $alpha),
                    default => pack('C4', $to8($px[0]), $to8($px[1]), $to8($px[2]), $to8($px[3])),
                };
            }
            return $out;
        };
    }

    /** 8-bit RGBA PNG of $image, without ancillary chunks (the same bytes on every host). */
    public static function encode(RgbaImage $image): string
    {
        $width = $image->width;
        $stride = $width * 4;
        $prev = array_fill(1, $stride, 0);
        $data = '';
        for ($y = 0; $y < $image->height; $y++) {
            /** @var array<int, int> $line */
            $line = unpack('C*', substr($image->pixels, $y * $stride, $stride));
            [$filter, $filtered] = self::bestFilter($line, $prev, $stride);
            $data .= pack('C*', $filter, ...$filtered);
            $prev = $line;
        }

        $ihdr = pack('NNCCCCC', $width, $image->height, 8, 6, 0, 0, 0);
        return self::SIGNATURE
            . self::chunk('IHDR', $ihdr)
            . self::chunk('IDAT', (string) gzcompress($data, 9))
            . self::chunk('IEND', '');
    }

    /**
     * @param array<int, int> $line 1-based
     * @param array<int, int> $prev 1-based
     * @return array{int, list<int>}
     */
    private static function bestFilter(array $line, array $prev, int $stride): array
    {
        $none = [];
        $sub = [];
        $up = [];
        $paeth = [];
        $sumNone = $sumSub = $sumUp = $sumPaeth = 0;
        for ($i = 1; $i <= $stride; $i++) {
            $x = $line[$i];
            $a = $i > 4 ? $line[$i - 4] : 0;
            $b = $prev[$i];
            $c = $i > 4 ? $prev[$i - 4] : 0;
            $pa = abs($b - $c);
            $pb = abs($a - $c);
            $pc = abs($a + $b - 2 * $c);
            $predictor = $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);

            $none[] = $x;
            $s = ($x - $a) & 0xff;
            $u = ($x - $b) & 0xff;
            $p = ($x - $predictor) & 0xff;
            $sub[] = $s;
            $up[] = $u;
            $paeth[] = $p;
            $sumNone += $x < 128 ? $x : 256 - $x;
            $sumSub += $s < 128 ? $s : 256 - $s;
            $sumUp += $u < 128 ? $u : 256 - $u;
            $sumPaeth += $p < 128 ? $p : 256 - $p;
        }
        $best = [0, $none, $sumNone];
        foreach ([[1, $sub, $sumSub], [2, $up, $sumUp], [4, $paeth, $sumPaeth]] as $candidate) {
            if ($candidate[2] < $best[2]) {
                $best = $candidate;
            }
        }
        return [$best[0], $best[1]];
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
