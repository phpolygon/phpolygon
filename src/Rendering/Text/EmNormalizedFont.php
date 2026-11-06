<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

/**
 * A copy of a font file whose glyphs php-vio's vio_text draws at the size its
 * HarfBuzz layout reserves for them.
 *
 * php-vio's shaped text path (up to at least 2.40.3, `vio_text_shape.c`) lays a
 * run out with HarfBuzz at `size` pixels per EM, but rasterises the glyphs with
 * stb_truetype's `ScaleForPixelHeight(size)`, which makes the font's hhea
 * ascender minus descender `size` pixels high. The two agree only for a font
 * whose ascender - descender equals its units per EM. Everywhere else every glyph
 * is drawn smaller than the advance it is given: Inter at 83 %, the Noto CJK faces
 * at 69 %, Noto Sans Thai at 66 %, Noto Sans Arabic (tall marks, deep descenders)
 * at 47 % - Arabic falls apart into tiny letters with gaps where they should join.
 *
 * The copy rescales the hhea ascender and descender (same ratio, sum = units per
 * EM). Neither HarfBuzz's horizontal shaping nor any glyph outline reads them, so
 * the copy differs from the source only in the scale stb_truetype derives: glyphs
 * fill their advances, at `size` pixels per EM. Advances, and with them every
 * measured width, are those of the source. A php-vio that rasterises per EM draws
 * the copy exactly like the source.
 */
final class EmNormalizedFont
{
    /** Ratios this close to 1 are left alone. */
    private const float TOLERANCE = 0.001;

    private function __construct() {}

    /**
     * Units per EM over the hhea ascender - descender: the share of `size` php-vio's
     * shaped path draws a glyph EM at. Null for anything that is not a single sfnt font.
     */
    public static function ratio(string $bytes): ?float
    {
        $metrics = self::metrics($bytes);
        if ($metrics === null) {
            return null;
        }
        [$upem, , $ascender, $descender] = $metrics;

        return $upem / ($ascender - $descender);
    }

    /**
     * The font with its hhea ascender - descender set to its units per EM, or null
     * when it needs no change or cannot be read (not sfnt, a collection, no head/hhea).
     */
    public static function patch(string $bytes): ?string
    {
        $metrics = self::metrics($bytes);
        if ($metrics === null) {
            return null;
        }
        [$upem, $hhea, $ascender, $descender] = $metrics;
        $height = $ascender - $descender;
        if (abs($upem / $height - 1.0) < self::TOLERANCE) {
            return null;
        }
        $newAscender = (int) round($ascender * $upem / $height);
        $newDescender = $newAscender - $upem;

        return substr_replace($bytes, pack('n', $newAscender & 0xFFFF) . pack('n', $newDescender & 0xFFFF), $hhea + 4, 4);
    }

    /**
     * Path of the normalised copy of $source in $cacheDir (default: the system temp
     * directory), written once per source file and kept; $source itself when the font
     * needs no change or the copy cannot be written. Loaders that read with plain
     * file I/O (vio_font_load_async) get a real file this way, also for a phar:// source.
     */
    public static function path(string $source, ?string $cacheDir = null): string
    {
        $size = @filesize($source);
        if ($size === false || $size < 12) {
            return $source;
        }
        $dir = rtrim($cacheDir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon-font-em', '/\\');
        $stamp = hash('xxh3', $source . '|' . $size . '|' . (string) @filemtime($source));
        $target = $dir . DIRECTORY_SEPARATOR . $stamp . '-' . basename($source);
        if (@filesize($target) === $size) {
            return $target;
        }
        $bytes = @file_get_contents($source);
        if (!is_string($bytes)) {
            return $source;
        }
        $patched = self::patch($bytes);
        if ($patched === null) {
            return $source;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return $source;
        }
        $temp = $target . '.part-' . getmypid();
        if (@file_put_contents($temp, $patched) !== strlen($patched)) {
            @unlink($temp);
            return $source;
        }
        if (!@rename($temp, $target)) {
            @unlink($temp);
            // Another process may have written it in the meantime.
            return @filesize($target) === $size ? $target : $source;
        }

        return $target;
    }

    /** @return array{int, int, int, int}|null units per EM, hhea offset, ascender, descender */
    private static function metrics(string $bytes): ?array
    {
        if (strlen($bytes) < 12) {
            return null;
        }
        $tag = substr($bytes, 0, 4);
        if ($tag !== "\x00\x01\x00\x00" && $tag !== 'OTTO' && $tag !== 'true') {
            return null;
        }
        $count = self::u16($bytes, 4);
        if (strlen($bytes) < 12 + 16 * $count) {
            return null;
        }
        $head = null;
        $hhea = null;
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            $name = substr($bytes, $record, 4);
            $offset = self::u32($bytes, $record + 8);
            if ($name === 'head') {
                $head = $offset;
            } elseif ($name === 'hhea') {
                $hhea = $offset;
            }
        }
        if ($head === null || $hhea === null || strlen($bytes) < $head + 20 || strlen($bytes) < $hhea + 8) {
            return null;
        }
        $upem = self::u16($bytes, $head + 18);
        $signed = static fn (int $v): int => $v >= 0x8000 ? $v - 0x10000 : $v;
        $ascender = $signed(self::u16($bytes, $hhea + 4));
        $descender = $signed(self::u16($bytes, $hhea + 6));
        if ($upem <= 0 || $ascender - $descender <= 0) {
            return null;
        }

        return [$upem, $hhea, $ascender, $descender];
    }

    /** Big-endian unsigned 16-bit value at $at (the caller checked the length). */
    private static function u16(string $bytes, int $at): int
    {
        return (ord($bytes[$at]) << 8) | ord($bytes[$at + 1]);
    }

    /** Big-endian unsigned 32-bit value at $at (the caller checked the length). */
    private static function u32(string $bytes, int $at): int
    {
        return (self::u16($bytes, $at) << 16) | self::u16($bytes, $at + 2);
    }
}
