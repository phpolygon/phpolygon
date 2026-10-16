<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Writes and unpacks KTX2 containers for pre-encoded GPU textures (BC7 and
 * other block or plain formats php-vio's `vio_texture_ktx2()` reads).
 *
 * Writing: {@see bc7()} / {@see build()} produce a minimal valid KTX2 - empty
 * data format descriptor (php-vio reads the format from vkFormat), optional
 * key/value data, levels stored smallest first while the level index lists
 * them base level first. With a zlib level > 0 every mip level is compressed on
 * its own with zlib (KTX2 supercompression scheme 3, {@see SUPERCOMPRESSION_ZLIB}).
 *
 * Reading: php-vio rejects supercompressed files, so {@see inflate()} turns a
 * scheme-3 container back into a plain one before the upload. A plain container
 * passes through untouched, Basis/Zstd schemes are refused (null).
 *
 * BC7 block data compresses well (hologram/text atlases ~8:1), so the installed
 * size shrinks while the upload stays the same; inflating costs a few ms per
 * megabyte at load time.
 */
final class Ktx2Container
{
    public const int VK_FORMAT_BC7_UNORM = 145;
    public const int VK_FORMAT_BC7_SRGB = 146;

    public const int SUPERCOMPRESSION_NONE = 0;
    public const int SUPERCOMPRESSION_ZLIB = 3;

    public const string IDENTIFIER = "\xABKTX 20\xBB\r\n\x1A\n";

    private const int HEADER_BYTES = 80;
    private const int LEVEL_ENTRY_BYTES = 24;

    private function __construct() {}

    /** Bytes of one BC7 level: 16 per 4x4 block, partial blocks round up. */
    public static function bc7LevelBytes(int $width, int $height): int
    {
        return max(1, intdiv($width + 3, 4)) * max(1, intdiv($height + 3, 4)) * 16;
    }

    /**
     * KTX2 container for a BC7 texture.
     *
     * @param list<string> $levels    block data per mip level, base level first,
     *                                each exactly {@see bc7LevelBytes()} long
     * @param int          $zlibLevel 0 = no supercompression, 1-9 = zlib per level
     */
    public static function bc7(int $width, int $height, array $levels, int $zlibLevel = 0, int $vkFormat = self::VK_FORMAT_BC7_UNORM): string
    {
        if ($width <= 0 || $height <= 0 || $levels === []) {
            throw new \InvalidArgumentException('a KTX2 texture needs a size and at least one level');
        }
        $w = $width;
        $h = $height;
        foreach ($levels as $i => $level) {
            if (strlen($level) !== self::bc7LevelBytes($w, $h)) {
                throw new \InvalidArgumentException(sprintf('level %d is %d bytes, %dx%d needs %d', $i, strlen($level), $w, $h, self::bc7LevelBytes($w, $h)));
            }
            $w = max(1, intdiv($w, 2));
            $h = max(1, intdiv($h, 2));
        }
        return self::build($vkFormat, 1, $width, $height, $levels, $zlibLevel);
    }

    /**
     * The block payload of a single-level .dds (e.g. from bc7enc): after the
     * 128-byte header, plus the 20-byte DX10 extension when present.
     */
    public static function ddsPayload(string $dds, int $width, int $height): string
    {
        if (strlen($dds) < 128 || !str_starts_with($dds, 'DDS ')) {
            throw new \InvalidArgumentException('not a DDS file');
        }
        $offset = 128 + (substr($dds, 84, 4) === 'DX10' ? 20 : 0);
        $need = self::bc7LevelBytes($width, $height);
        if (strlen($dds) - $offset < $need) {
            throw new \InvalidArgumentException(sprintf('DDS holds %d block bytes, %dx%d needs %d', strlen($dds) - $offset, $width, $height, $need));
        }
        return substr($dds, $offset, $need);
    }

    /**
     * A 2D KTX2 container (one layer, one face).
     *
     * @param list<string> $levels    level data, base level first
     * @param int          $typeSize  1 for block-compressed formats
     * @param int          $zlibLevel 0 = no supercompression, 1-9 = zlib per level
     * @param string       $kvd       raw key/value data block ('' = none)
     */
    public static function build(int $vkFormat, int $typeSize, int $width, int $height, array $levels, int $zlibLevel = 0, string $kvd = ''): string
    {
        if ($zlibLevel < 0 || $zlibLevel > 9) {
            throw new \InvalidArgumentException("zlib level must be 0-9, got {$zlibLevel}");
        }
        $stored = $levels;
        if ($zlibLevel > 0) {
            $stored = [];
            foreach ($levels as $i => $level) {
                $packed = gzcompress($level, $zlibLevel);
                if ($packed === false) {
                    throw new \RuntimeException("zlib compression of KTX2 level {$i} failed");
                }
                $stored[] = $packed;
            }
        }
        return self::assemble(
            [$vkFormat, $typeSize, $width, $height, 0, 0, 1],
            pack('V', 4),
            $kvd,
            $stored,
            array_map('strlen', $levels),
            $zlibLevel > 0 ? self::SUPERCOMPRESSION_ZLIB : self::SUPERCOMPRESSION_NONE,
        );
    }

    /** The supercompression scheme of a KTX2 container, or null when $bytes is none. */
    public static function supercompression(string $bytes): ?int
    {
        if (strlen($bytes) < self::HEADER_BYTES || !str_starts_with($bytes, self::IDENTIFIER)) {
            return null;
        }
        $scheme = unpack('V', $bytes, 44);
        return $scheme === false || !is_int($scheme[1]) ? null : $scheme[1];
    }

    /**
     * A container without supercompression for $bytes: unchanged when it has
     * none, every level inflated for zlib (scheme 3). Null for anything else -
     * not KTX2, a scheme php-vio and this class cannot unpack, a truncated or
     * corrupt level.
     */
    public static function inflate(string $bytes): ?string
    {
        $scheme = self::supercompression($bytes);
        if ($scheme === self::SUPERCOMPRESSION_NONE) {
            return $bytes;
        }
        if ($scheme !== self::SUPERCOMPRESSION_ZLIB) {
            return null;
        }

        $head = unpack('V9', $bytes, 12);
        $index = unpack('V4', $bytes, 48);
        if ($head === false || $index === false) {
            return null;
        }
        /** @var array<int, int> $head */
        /** @var array<int, int> $index */
        $levelCount = max(1, $head[8]);
        if (self::HEADER_BYTES + $levelCount * self::LEVEL_ENTRY_BYTES > strlen($bytes)) {
            return null;
        }

        $levels = [];
        $sizes = [];
        for ($i = 0; $i < $levelCount; $i++) {
            $entry = unpack('P3', $bytes, self::HEADER_BYTES + $i * self::LEVEL_ENTRY_BYTES);
            if ($entry === false) {
                return null;
            }
            /** @var array<int, int> $entry */
            [$offset, $length, $uncompressed] = [$entry[1], $entry[2], $entry[3]];
            if ($offset + $length > strlen($bytes)) {
                return null;
            }
            $level = @gzuncompress(substr($bytes, $offset, $length));
            if ($level === false || strlen($level) !== $uncompressed) {
                return null;
            }
            $levels[] = $level;
            $sizes[] = $uncompressed;
        }

        $dfd = substr($bytes, $index[1], $index[2]);
        $kvd = $index[4] > 0 ? substr($bytes, $index[3], $index[4]) : '';

        return self::assemble(
            [$head[1], $head[2], $head[3], $head[4], $head[5], $head[6], $head[7]],
            $dfd,
            $kvd,
            $levels,
            $sizes,
            self::SUPERCOMPRESSION_NONE,
        );
    }

    /**
     * @param array{int, int, int, int, int, int, int} $fields vkFormat, typeSize, width, height, depth, layerCount, faceCount
     * @param list<string> $levels stored level bytes, base level first
     * @param list<int>    $uncompressedSizes per level
     */
    private static function assemble(array $fields, string $dfd, string $kvd, array $levels, array $uncompressedSizes, int $scheme): string
    {
        $count = count($levels);
        $header = self::IDENTIFIER . pack('V9', ...[...$fields, $count, $scheme]);

        $dfdOffset = self::HEADER_BYTES + $count * self::LEVEL_ENTRY_BYTES;
        $kvdOffset = $kvd === '' ? 0 : $dfdOffset + strlen($dfd);
        $metaEnd = $dfdOffset + strlen($dfd) + strlen($kvd);
        // Plain levels align to lcm(texel block, 4) - 16 covers every format;
        // supercompressed levels need no alignment.
        $align = $scheme === self::SUPERCOMPRESSION_NONE ? 16 : 1;
        $dataStart = intdiv($metaEnd + $align - 1, $align) * $align;

        $data = '';
        $offsets = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $pad = ($align - (($dataStart + strlen($data)) % $align)) % $align;
            $data .= str_repeat("\0", $pad);
            $offsets[$i] = $dataStart + strlen($data);
            $data .= $levels[$i];
        }

        // dfdByteOffset, dfdByteLength, kvdByteOffset, kvdByteLength, sgdByteOffset, sgdByteLength
        $index = pack('V4', $dfdOffset, strlen($dfd), $kvdOffset, strlen($kvd)) . pack('P2', 0, 0);
        $levelIndex = '';
        for ($i = 0; $i < $count; $i++) {
            $levelIndex .= pack('P3', $offsets[$i], strlen($levels[$i]), $uncompressedSizes[$i]);
        }

        return str_pad($header . $index . $levelIndex . $dfd . $kvd, $dataStart, "\0") . $data;
    }
}
