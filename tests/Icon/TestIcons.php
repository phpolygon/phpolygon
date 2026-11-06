<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Icon;

use PHPolygon\Icon\RgbaImage;

/**
 * Neutral generated icon sources for the tests: a rounded square (transparent
 * corners) in blue with a yellow disc and a red bar, so every size has
 * transparency, hard edges and flat areas.
 */
final class TestIcons
{
    public static function pattern(int $size): RgbaImage
    {
        $pixels = '';
        $radius = $size * 0.2;
        $c = ($size - 1) / 2;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                // Rounded corners: outside the corner circles is transparent.
                $dx = max($radius - $x - 0.5, $x + 0.5 - ($size - $radius), 0);
                $dy = max($radius - $y - 0.5, $y + 0.5 - ($size - $radius), 0);
                if ($dx * $dx + $dy * $dy > $radius * $radius) {
                    $pixels .= "\0\0\0\0";
                    continue;
                }
                $rgba = [30, 60, 140, 255];
                if (($x - $c) ** 2 + ($y - $c) ** 2 < ($size * 0.3) ** 2) {
                    $rgba = [240, 200, 40, 255];
                }
                if (abs($x - $y) < $size * 0.06) {
                    $rgba = [200, 40, 40, 255];
                }
                $pixels .= pack('C4', ...$rgba);
            }
        }
        return new RgbaImage($size, $size, $pixels);
    }

    /** A flat colour square. */
    public static function solid(int $width, int $height, int $r, int $g, int $b, int $a = 255): RgbaImage
    {
        return new RgbaImage($width, $height, str_repeat(pack('C4', $r, $g, $b, $a), $width * $height));
    }
}
