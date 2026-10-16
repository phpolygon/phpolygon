<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Binary MeshData container (little-endian), shared by the mesh cache, the
 * prototype exporter and the compiled mesh assets a build ships.
 *
 * Version 1 ({@see encode()}): 28-byte header `PHMC`, format, reserved,
 * versionHash, vertex/normal/uv/index counts; float32 vertices, normals, uvs,
 * then uint32 indices.
 *
 * Version 2 ({@see encodeCompact()}): 32-byte header `PHMC`, format, flags,
 * versionHash, vertex/normal/uv/index/tangent counts; the payload is the same
 * float32 streams plus tangents, followed by the indices as uint16
 * ({@see FLAG_INDEX16}, whenever every index fits) or uint32. With
 * {@see FLAG_ZLIB} the payload after the header is zlib-compressed
 * (`gzcompress`). {@see decode()} reads both versions.
 *
 * `versionHash` is crc32 of a caller-chosen string: the generator version for
 * the cache, the source file's bytes for a compiled asset (so a loader can see
 * whether the binary still matches its source).
 */
class MeshCacheIO
{
    private const MAGIC = 'PHMC';
    private const FORMAT_VERSION = 1;
    private const HEADER_SIZE = 28;

    public const COMPACT_FORMAT_VERSION = 2;
    private const COMPACT_HEADER_SIZE = 32;

    /** Indices are stored as uint16. */
    public const FLAG_INDEX16 = 1;
    /** The payload after the header is zlib-compressed. */
    public const FLAG_ZLIB = 2;

    public static function encode(MeshData $mesh, string $version): string
    {
        $header = pack(
            'a4vvVVVVV',
            self::MAGIC,
            self::FORMAT_VERSION,
            0, // reserved
            self::versionHash($version),
            count($mesh->vertices),
            count($mesh->normals),
            count($mesh->uvs),
            count($mesh->indices),
        );

        $data = pack('g*', ...$mesh->vertices);
        $data .= pack('g*', ...$mesh->normals);
        $data .= pack('g*', ...$mesh->uvs);

        if (count($mesh->indices) > 0) {
            $data .= pack('V*', ...$mesh->indices);
        }

        return $header . $data;
    }

    /**
     * Version-2 encoding: uint16 indices whenever they fit, tangents kept,
     * optionally zlib-compressed.
     *
     * @param int $zlibLevel 0 = store the payload raw, 1-9 = gzcompress level
     */
    public static function encodeCompact(MeshData $mesh, string $version, int $zlibLevel = 6): string
    {
        $tangents = $mesh->tangents ?? [];
        $index16 = $mesh->indices === [] || (max($mesh->indices) <= 0xFFFF && min($mesh->indices) >= 0);
        $flags = $index16 ? self::FLAG_INDEX16 : 0;

        $payload = pack('g*', ...$mesh->vertices)
            . pack('g*', ...$mesh->normals)
            . pack('g*', ...$mesh->uvs)
            . pack('g*', ...$tangents);
        if ($mesh->indices !== []) {
            $payload .= pack($index16 ? 'v*' : 'V*', ...$mesh->indices);
        }

        if ($zlibLevel > 0) {
            $compressed = gzcompress($payload, min(9, $zlibLevel));
            if ($compressed === false) {
                throw new \RuntimeException('zlib compression of a mesh payload failed');
            }
            $payload = $compressed;
            $flags |= self::FLAG_ZLIB;
        }

        return pack(
            'a4vvVVVVVV',
            self::MAGIC,
            self::COMPACT_FORMAT_VERSION,
            $flags,
            self::versionHash($version),
            count($mesh->vertices),
            count($mesh->normals),
            count($mesh->uvs),
            count($mesh->indices),
            count($tangents),
        ) . $payload;
    }

    public static function decode(string $data): ?MeshData
    {
        if (strlen($data) < 8 || substr($data, 0, 4) !== self::MAGIC) {
            return null;
        }
        $format = unpack('v', $data, 4);
        if ($format === false) {
            return null;
        }

        return match ($format[1]) {
            self::FORMAT_VERSION => self::decodeV1($data),
            self::COMPACT_FORMAT_VERSION => self::decodeV2($data),
            default => null,
        };
    }

    private static function decodeV1(string $data): ?MeshData
    {
        if (strlen($data) < self::HEADER_SIZE) {
            return null;
        }

        $header = unpack(
            'a4magic/vformatVersion/vreserved/VversionHash/VvertexCount/VnormalCount/VuvCount/VindexCount',
            substr($data, 0, self::HEADER_SIZE),
        );

        if ($header === false) {
            return null;
        }

        /** @var array{vertexCount: int, normalCount: int, uvCount: int, indexCount: int} $header */
        $vertexCount = $header['vertexCount'];
        $normalCount = $header['normalCount'];
        $uvCount = $header['uvCount'];
        $indexCount = $header['indexCount'];

        $expectedSize = self::HEADER_SIZE
            + ($vertexCount * 4)
            + ($normalCount * 4)
            + ($uvCount * 4)
            + ($indexCount * 4);

        if (strlen($data) < $expectedSize) {
            return null;
        }

        $offset = self::HEADER_SIZE;

        $vertices = self::unpackFloats($data, $offset, $vertexCount);
        $offset += $vertexCount * 4;

        $normals = self::unpackFloats($data, $offset, $normalCount);
        $offset += $normalCount * 4;

        $uvs = self::unpackFloats($data, $offset, $uvCount);
        $offset += $uvCount * 4;

        $indices = self::unpackInts($data, $offset, $indexCount, 'V');

        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    private static function decodeV2(string $data): ?MeshData
    {
        if (strlen($data) < self::COMPACT_HEADER_SIZE) {
            return null;
        }
        $header = unpack(
            'a4magic/vformatVersion/vflags/VversionHash/VvertexCount/VnormalCount/VuvCount/VindexCount/VtangentCount',
            $data,
        );
        if ($header === false) {
            return null;
        }

        /** @var array{flags: int, vertexCount: int, normalCount: int, uvCount: int, indexCount: int, tangentCount: int} $header */
        $flags = $header['flags'];
        $indexWidth = ($flags & self::FLAG_INDEX16) !== 0 ? 2 : 4;
        $floatCount = $header['vertexCount'] + $header['normalCount'] + $header['uvCount'] + $header['tangentCount'];
        $payloadSize = $floatCount * 4 + $header['indexCount'] * $indexWidth;

        $offset = self::COMPACT_HEADER_SIZE;
        if (($flags & self::FLAG_ZLIB) !== 0) {
            $inflated = @gzuncompress(substr($data, $offset));
            if ($inflated === false) {
                return null;
            }
            $data = $inflated;
            $offset = 0;
        }
        if (strlen($data) - $offset < $payloadSize) {
            return null;
        }

        $vertices = self::unpackFloats($data, $offset, $header['vertexCount']);
        $offset += $header['vertexCount'] * 4;
        $normals = self::unpackFloats($data, $offset, $header['normalCount']);
        $offset += $header['normalCount'] * 4;
        $uvs = self::unpackFloats($data, $offset, $header['uvCount']);
        $offset += $header['uvCount'] * 4;
        $tangents = $header['tangentCount'] > 0 ? self::unpackFloats($data, $offset, $header['tangentCount']) : null;
        $offset += $header['tangentCount'] * 4;
        $indices = self::unpackInts($data, $offset, $header['indexCount'], $indexWidth === 2 ? 'v' : 'V');

        return new MeshData($vertices, $normals, $uvs, $indices, $tangents);
    }

    public static function readVersionHash(string $data): ?int
    {
        if (strlen($data) < 12) {
            return null;
        }

        $header = unpack('a4magic/vformatVersion/vreserved/VversionHash', substr($data, 0, 12));

        if ($header === false || $header['magic'] !== self::MAGIC) {
            return null;
        }

        /** @var array{versionHash: int} $header */
        return $header['versionHash'];
    }

    public static function versionHash(string $version): int
    {
        return crc32($version) & 0xFFFFFFFF;
    }

    /**
     * @return float[]
     */
    private static function unpackFloats(string $data, int $offset, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $unpacked = unpack('g' . $count, $data, $offset);

        /** @var float[] */
        return $unpacked !== false ? array_values($unpacked) : [];
    }

    /**
     * @return int[]
     */
    private static function unpackInts(string $data, int $offset, int $count, string $code): array
    {
        if ($count === 0) {
            return [];
        }

        $unpacked = unpack($code . $count, $data, $offset);

        /** @var int[] */
        return $unpacked !== false ? array_values($unpacked) : [];
    }
}
