<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A compact text form for lattice data (a stored height field, its zone bytes) that can live in a PHP class constant:
 * delta coding + zlib + base64.
 *
 * HEIGHTS. One uint16 per corner, `round((height + offset) * scale)` (default scale 10000 = 0.1 mm; the range is
 * (65535 / scale) m, so choose `offset` to put the lowest ground at 0); 0xFFFF = no ground (null). A row (`$width`
 * corners) is stored as the difference to the previous GROUND corner of the row (modulo 65536, the first of a row
 * against 0), which turns smooth terrain into small repeating numbers that zlib squeezes well; the compressed bytes are
 * base64. The decoder gives back exactly the quantised values (within half a step of the input).
 *
 * BYTES. Any byte string (zone numbers, masks): zlib + base64.
 *
 * Pure functions, no state: same input, same output on every platform.
 */
final class LatticeCodec
{
    /** The uint16 marking a corner without ground. */
    public const int VOID = 0xFFFF;

    private function __construct()
    {
    }

    /**
     * @param list<float|null> $corners row-major, `$width` corners per row; null = no ground
     * @param float $offset added to a height before quantising
     * @param float $scale quanta per metre
     * @param int $level zlib level 0..9
     * @return string base64 of the zlib stream
     */
    public static function packHeights(array $corners, int $width, float $offset = 0.0, float $scale = 10000.0, int $level = 9): string
    {
        if ($width < 1 || $scale <= 0.0 || count($corners) % $width !== 0) {
            throw new \InvalidArgumentException('LatticeCodec::packHeights: needs width >= 1, scale > 0 and whole rows');
        }
        $raw = '';
        $prev = 0;
        $k = 0;
        foreach ($corners as $h) {
            if ($k % $width === 0) {
                $prev = 0;
            }
            $k++;
            if ($h === null) {
                // A void is stored like any value (0xFFFF against the previous ground corner) and does not move $prev.
                $raw .= pack('v', (self::VOID - $prev) & 0xFFFF);
                continue;
            }
            $v = (int) round(($h + $offset) * $scale);
            if ($v < 0 || $v >= self::VOID) {
                throw new \InvalidArgumentException("LatticeCodec::packHeights: height {$h} is outside the packable range");
            }
            $raw .= pack('v', ($v - $prev) & 0xFFFF);
            $prev = $v;
        }

        return base64_encode((string) gzcompress($raw, $level));
    }

    /**
     * The absolute quantised values as a binary string, uint16 little endian per corner ({@see VOID} = no ground): what
     * {@see packHeights()} quantised, without building a PHP array (a large field stays at two bytes per corner).
     * Read it with {@see quantisedAt()}.
     */
    public static function unpackQuantised(string $packed, int $width): string
    {
        $diff = @gzuncompress((string) base64_decode($packed, true));
        if ($diff === false || $width < 1 || strlen($diff) % 2 !== 0) {
            throw new \InvalidArgumentException('LatticeCodec: the packed heights are damaged');
        }
        $count = intdiv(strlen($diff), 2);
        $abs = '';
        $prev = 0;
        for ($k = 0; $k < $count; $k++) {
            if ($k % $width === 0) {
                $prev = 0;
            }
            $d = \ord($diff[2 * $k]) | (\ord($diff[2 * $k + 1]) << 8);
            $v = ($prev + $d) & 0xFFFF;
            $abs .= \chr($v & 0xFF) . \chr($v >> 8);
            if ($v !== self::VOID) {
                $prev = $v;
            }
        }

        return $abs;
    }

    /** Height of corner `$k` of an {@see unpackQuantised()} string, null where there is no ground. */
    public static function quantisedAt(string $quantised, int $k, float $offset = 0.0, float $scale = 10000.0): ?float
    {
        $v = \ord($quantised[2 * $k]) | (\ord($quantised[2 * $k + 1]) << 8);

        return $v === self::VOID ? null : $v / $scale - $offset;
    }

    /**
     * @param float $offset the same as at packing
     * @param float $scale the same as at packing
     * @return list<float|null>
     */
    public static function unpackHeights(string $packed, int $width, float $offset = 0.0, float $scale = 10000.0): array
    {
        $q = self::unpackQuantised($packed, $width);
        $out = [];
        for ($k = 0, $n = intdiv(strlen($q), 2); $k < $n; $k++) {
            $out[] = self::quantisedAt($q, $k, $offset, $scale);
        }

        return $out;
    }

    public static function packBytes(string $bytes, int $level = 9): string
    {
        return base64_encode((string) gzcompress($bytes, $level));
    }

    public static function unpackBytes(string $packed): string
    {
        $raw = @gzuncompress((string) base64_decode($packed, true));
        if ($raw === false) {
            throw new \InvalidArgumentException('LatticeCodec: the packed bytes are damaged');
        }

        return $raw;
    }
}
