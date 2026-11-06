<?php

declare(strict_types=1);

namespace PHPolygon\Build\Icon;

use PHPolygon\Icon\Png;
use PHPolygon\Icon\RgbaImage;

/**
 * Apple .icns files with PNG entries only (OS X 10.7 and later read PNG in every
 * type below), so no Apple tool is needed: a build on Windows or Linux writes the
 * same file as one on a Mac.
 *
 * Layout: 'icns' + big-endian total length, a 'TOC ' entry listing the entries
 * that follow (as iconutil writes it), then one entry per type: four-character
 * code + big-endian length (including the 8 header bytes) + PNG.
 */
final class IcnsFile
{
    /**
     * Type code => pixel size. The @2x types hold the same pixels as the 1x type
     * of twice the size; both are written so every lookup finds an exact entry.
     */
    public const TYPES = [
        'icp4' => 16,
        'ic11' => 32,   // 16 pt @2x
        'icp5' => 32,
        'ic12' => 64,   // 32 pt @2x (no icp6: iconutil reads it as 48 px and scales it)
        'ic07' => 128,
        'ic13' => 256,  // 128 pt @2x
        'ic08' => 256,
        'ic14' => 512,  // 256 pt @2x
        'ic09' => 512,
        'ic10' => 1024, // 512 pt @2x
    ];

    /** The pixel sizes {@see self::TYPES} needs. */
    public const SIZES = [16, 32, 64, 128, 256, 512, 1024];

    /**
     * An .icns from square images keyed by size. Types whose size is missing
     * (a source below 1024 px) are left out; at least one must be there.
     *
     * @param array<int, RgbaImage> $images size => image
     */
    public static function build(array $images): string
    {
        $pngs = [];
        foreach ($images as $size => $image) {
            $pngs[$size] = Png::encode($image);
        }
        $entries = [];
        foreach (self::TYPES as $type => $size) {
            if (isset($pngs[$size])) {
                $entries[$type] = $pngs[$size];
            }
        }
        if ($entries === []) {
            throw new \InvalidArgumentException('An .icns needs at least one of the sizes ' . implode(', ', self::SIZES));
        }

        $toc = '';
        $body = '';
        foreach ($entries as $type => $png) {
            $length = 8 + strlen($png);
            $toc .= $type . pack('N', $length);
            $body .= $type . pack('N', $length) . $png;
        }
        $toc = 'TOC ' . pack('N', 8 + strlen($toc)) . $toc;
        return 'icns' . pack('N', 8 + strlen($toc) + strlen($body)) . $toc . $body;
    }

    /**
     * The entries of an .icns, type => data (the TOC included).
     *
     * @return array<string, string>
     */
    public static function parse(string $icns): array
    {
        if (strlen($icns) < 8 || substr($icns, 0, 4) !== 'icns') {
            throw new \InvalidArgumentException('Not an .icns file');
        }
        /** @var array{1: int} $total */
        $total = unpack('N', $icns, 4);
        if ($total[1] !== strlen($icns)) {
            throw new \InvalidArgumentException("The .icns header says {$total[1]} bytes, the file has " . strlen($icns));
        }
        $entries = [];
        for ($pos = 8; $pos < $total[1];) {
            if ($pos + 8 > $total[1]) {
                throw new \InvalidArgumentException('An .icns entry header is cut off');
            }
            $type = substr($icns, $pos, 4);
            /** @var array{1: int} $length */
            $length = unpack('N', $icns, $pos + 4);
            if ($length[1] < 8 || $pos + $length[1] > $total[1]) {
                throw new \InvalidArgumentException("The .icns entry {$type} has a bad length");
            }
            $entries[$type] = substr($icns, $pos + 8, $length[1] - 8);
            $pos += $length[1];
        }
        return $entries;
    }
}
