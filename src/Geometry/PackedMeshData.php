<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A mesh as flat little-endian byte strings: the form a mesh takes while it
 * crosses a thread boundary, waits in a queue or sits resident without being
 * drawn. {@see MeshData} holds PHP float arrays (16 bytes per element plus
 * hash overhead, one `zval` each), which `parallel` copies element by element;
 * a string is one memcpy. {@see pack()} turns the whole container into ONE
 * string, {@see unpack()} reads it back without touching the floats.
 *
 * Layout of {@see pack()} (version 1, 64-byte header):
 *
 *   0  'PPK1'                         magic
 *   4  u8 flags                       see FLAG_*
 *   5  3 bytes reserved
 *   8  u32 vertexCount
 *  12  u32 indexCount
 *  16  6 x f64 bounds                min xyz, max xyz of the positions
 *  64  f32 positions (3 per vertex), f32 normals (3), f32 uvs (2),
 *      f32 tangents (4, FLAG_TANGENTS), f32 colors (4 = RGBA, FLAG_COLORS),
 *      u16 or u32 indices (FLAG_INDEX32)
 *
 * Normals and uvs may be empty (FLAG_NO_NORMALS / FLAG_NO_UVS); every other
 * stream is exactly as long as the vertex count says.
 *
 * What "exact" means: positions, normals, uvs, tangents and colors are stored
 * as float32 (what the GPU reads, and half the bytes of a double). A
 * {@see MeshData} whose floats are float32 values (everything that came out of
 * {@see unpack()}, {@see MeshCacheIO::decode()} or an earlier round trip)
 * survives `fromMeshData()` -> `toMeshData()` bit for bit; a PHP double is
 * rounded to the nearest float32 once and is stable from then on. Container
 * to string to container is byte-identical always. Bounds are f64 and measured
 * on the already rounded positions, so they match what `toMeshData()->bounds()`
 * reports. Indices are u16 when the vertex count is at most 65536 (the width
 * `vio_mesh` stores them in), else u32.
 *
 * Not {@see MeshCacheIO}: that is a disk format (version hash, compression,
 * decode straight to arrays) with no colors and no bounds. This container exists
 * to stay packed: its streams are directly usable as GPU upload bytes
 * ({@see gpuVertexBytes()}, {@see gpuIndexBytes()}) and its byte size is known
 * without unpacking, which a per-frame upload budget needs.
 *
 * Cost note for the thread that owns the registry: {@see toMeshData()} is the
 * expensive direction (PHP arrays must be built): 3 to 5 ms per MB of packed
 * mesh, measured 2026-11-14 (PHP 8.5.5 ZTS, Windows; `benchmarks/streaming-glue.php mesh`),
 * against 0.03 ms per MB for {@see pack()} / {@see unpack()}. Do the packing,
 * and {@see gpuVertexBytes()}, in the worker.
 */
final class PackedMeshData
{
    public const int FLAG_TANGENTS = 1;
    public const int FLAG_COLORS = 2;
    public const int FLAG_INDEX32 = 4;
    public const int FLAG_NO_NORMALS = 8;
    public const int FLAG_NO_UVS = 16;

    public const int HEADER_SIZE = 64;

    private const string MAGIC = 'PPK1';

    /** Largest vertex count whose indices fit u16. */
    private const int INDEX16_MAX_VERTICES = 65536;

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $bounds min xyz, max xyz
     */
    private function __construct(
        public readonly int $vertexCount,
        public readonly int $indexCount,
        public readonly string $positions,
        public readonly string $normals,
        public readonly string $uvs,
        public readonly string $indices,
        public readonly ?string $tangents,
        public readonly ?string $colors,
        public readonly bool $index32,
        public readonly array $bounds,
    ) {}

    /**
     * @param ?array<float> $colors flat RGBA per vertex (4 floats), or null
     */
    public static function fromMeshData(MeshData $mesh, ?array $colors = null): self
    {
        return self::fromArrays($mesh->vertices, $mesh->normals, $mesh->uvs, $mesh->indices, $mesh->tangents, $colors);
    }

    /**
     * @param array<float>  $positions flat xyz per vertex
     * @param array<float>  $normals   flat xyz per vertex, or empty
     * @param array<float>  $uvs       flat uv per vertex, or empty
     * @param array<int>    $indices   triangle list
     * @param ?array<float> $tangents  flat xyzw per vertex
     * @param ?array<float> $colors    flat RGBA per vertex
     */
    public static function fromArrays(
        array $positions,
        array $normals,
        array $uvs,
        array $indices,
        ?array $tangents = null,
        ?array $colors = null,
    ): self {
        $n = intdiv(count($positions), 3);
        if (count($positions) !== $n * 3) {
            throw new \InvalidArgumentException('PackedMeshData: positions length is not a multiple of 3.');
        }
        if ($normals !== [] && count($normals) !== $n * 3) {
            throw new \InvalidArgumentException('PackedMeshData: normals must be empty or 3 floats per vertex.');
        }
        if ($uvs !== [] && count($uvs) !== $n * 2) {
            throw new \InvalidArgumentException('PackedMeshData: uvs must be empty or 2 floats per vertex.');
        }
        if ($tangents !== null && count($tangents) !== $n * 4) {
            throw new \InvalidArgumentException('PackedMeshData: tangents must be 4 floats per vertex.');
        }
        if ($colors !== null && count($colors) !== $n * 4) {
            throw new \InvalidArgumentException('PackedMeshData: colors must be 4 floats (RGBA) per vertex.');
        }
        $indexCount = count($indices);
        if ($indexCount % 3 !== 0) {
            throw new \InvalidArgumentException('PackedMeshData: index count is not a multiple of 3.');
        }

        $index32 = $n > self::INDEX16_MAX_VERTICES;
        $packedIndices = '';
        if ($indexCount > 0) {
            $min = min($indices);
            $max = max($indices);
            if ($min < 0 || $max >= $n) {
                throw new \InvalidArgumentException("PackedMeshData: index out of range 0..{$n} ({$min}..{$max}).");
            }
            $packedIndices = self::packInts($indices, $index32 ? 'V' : 'v');
        }

        $positionBytes = self::packFloats($positions);

        return new self(
            $n,
            $indexCount,
            $positionBytes,
            $normals === [] ? '' : self::packFloats($normals),
            $uvs === [] ? '' : self::packFloats($uvs),
            $packedIndices,
            $tangents === null ? null : self::packFloats($tangents),
            $colors === null ? null : self::packFloats($colors),
            $index32,
            self::boundsOfPacked($positionBytes, $n),
        );
    }

    /** The mesh as PHP arrays. Expensive for big meshes: see the class doc. Colors are not part of MeshData; use {@see colorArray()}. */
    public function toMeshData(): MeshData
    {
        return new MeshData(
            self::floats($this->positions),
            self::floats($this->normals),
            self::floats($this->uvs),
            self::ints($this->indices, $this->index32 ? 'V' : 'v'),
            $this->tangents === null ? null : self::floats($this->tangents),
        );
    }

    /** @return ?list<float> flat RGBA per vertex, null without colors */
    public function colorArray(): ?array
    {
        return $this->colors === null ? null : self::floats($this->colors);
    }

    /** The whole container as one string (crosses a thread boundary as a single copy). */
    public function pack(): string
    {
        $flags = ($this->tangents !== null ? self::FLAG_TANGENTS : 0)
            | ($this->colors !== null ? self::FLAG_COLORS : 0)
            | ($this->index32 ? self::FLAG_INDEX32 : 0)
            | ($this->normals === '' ? self::FLAG_NO_NORMALS : 0)
            | ($this->uvs === '' ? self::FLAG_NO_UVS : 0);

        return pack(
            'a4Cx3VVe6',
            self::MAGIC,
            $flags,
            $this->vertexCount,
            $this->indexCount,
            ...$this->bounds,
        ) . $this->positions . $this->normals . $this->uvs . ($this->tangents ?? '') . ($this->colors ?? '') . $this->indices;
    }

    /** @throws \InvalidArgumentException when $data is not a container of this version or has the wrong length */
    public static function unpack(string $data): self
    {
        if (strlen($data) < self::HEADER_SIZE || substr($data, 0, 4) !== self::MAGIC) {
            throw new \InvalidArgumentException('PackedMeshData: not a PPK1 container.');
        }
        $h = unpack('Cflags/x3/VvertexCount/VindexCount/e6b', $data, 4);
        if ($h === false) {
            throw new \InvalidArgumentException('PackedMeshData: unreadable header.');
        }
        /** @var array{flags: int, vertexCount: int, indexCount: int, b1: float, b2: float, b3: float, b4: float, b5: float, b6: float} $h */
        $flags = $h['flags'];
        $n = $h['vertexCount'];
        $indexCount = $h['indexCount'];
        $index32 = ($flags & self::FLAG_INDEX32) !== 0;

        $sizes = [
            'positions' => $n * 12,
            'normals' => ($flags & self::FLAG_NO_NORMALS) !== 0 ? 0 : $n * 12,
            'uvs' => ($flags & self::FLAG_NO_UVS) !== 0 ? 0 : $n * 8,
            'tangents' => ($flags & self::FLAG_TANGENTS) !== 0 ? $n * 16 : 0,
            'colors' => ($flags & self::FLAG_COLORS) !== 0 ? $n * 16 : 0,
            'indices' => $indexCount * ($index32 ? 4 : 2),
        ];
        if (strlen($data) !== self::HEADER_SIZE + array_sum($sizes)) {
            throw new \InvalidArgumentException(
                'PackedMeshData: length ' . strlen($data) . ' does not match the header (expected '
                . (self::HEADER_SIZE + array_sum($sizes)) . ').',
            );
        }

        $offset = self::HEADER_SIZE;
        $part = [];
        foreach ($sizes as $name => $size) {
            $part[$name] = $size === 0 ? '' : substr($data, $offset, $size);
            $offset += $size;
        }

        return new self(
            $n,
            $indexCount,
            $part['positions'],
            $part['normals'],
            $part['uvs'],
            $part['indices'],
            ($flags & self::FLAG_TANGENTS) !== 0 ? $part['tangents'] : null,
            ($flags & self::FLAG_COLORS) !== 0 ? $part['colors'] : null,
            $index32,
            [$h['b1'], $h['b2'], $h['b3'], $h['b4'], $h['b5'], $h['b6']],
        );
    }

    /** Bytes of {@see pack()}'s result: what an upload budget charges for this mesh. */
    public function byteSize(): int
    {
        return self::HEADER_SIZE + strlen($this->positions) + strlen($this->normals) + strlen($this->uvs)
            + strlen($this->tangents ?? '') + strlen($this->colors ?? '') + strlen($this->indices);
    }

    public function triangleCount(): int
    {
        return intdiv($this->indexCount, 3);
    }

    /**
     * Axis-aligned box of the positions, same shape as {@see MeshData::bounds()}.
     *
     * @return array{min: \PHPolygon\Math\Vec3, max: \PHPolygon\Math\Vec3}
     */
    public function bounds(): array
    {
        return [
            'min' => new \PHPolygon\Math\Vec3($this->bounds[0], $this->bounds[1], $this->bounds[2]),
            'max' => new \PHPolygon\Math\Vec3($this->bounds[3], $this->bounds[4], $this->bounds[5]),
        ];
    }

    /**
     * Position, normal and uv interleaved per vertex as float32 (32 bytes per
     * vertex): the layout the renderer builds for `vio_mesh()`. A PHP loop,
     * built for a worker; zero normals / uvs stand in for missing streams.
     */
    public function gpuVertexBytes(): string
    {
        $out = '';
        $chunk = 4096;
        for ($start = 0; $start < $this->vertexCount; $start += $chunk) {
            $count = min($chunk, $this->vertexCount - $start);
            $p = self::sliceFloats($this->positions, $start * 3, $count * 3);
            $nrm = $this->normals === '' ? array_fill(0, $count * 3, 0.0) : self::sliceFloats($this->normals, $start * 3, $count * 3);
            $uv = $this->uvs === '' ? array_fill(0, $count * 2, 0.0) : self::sliceFloats($this->uvs, $start * 2, $count * 2);
            $row = [];
            for ($i = 0; $i < $count; $i++) {
                $a = $i * 3;
                $b = $i * 2;
                array_push($row, $p[$a], $p[$a + 1], $p[$a + 2], $nrm[$a], $nrm[$a + 1], $nrm[$a + 2], $uv[$b], $uv[$b + 1]);
            }
            $out .= pack('g*', ...$row);
        }

        return $out;
    }

    /** The index stream as stored: u16 for at most 65536 vertices, else u32 (the width `vio_mesh` uses). */
    public function gpuIndexBytes(): string
    {
        return $this->indices;
    }

    /** @param array<float> $values */
    private static function packFloats(array $values): string
    {
        return $values === [] ? '' : pack('g*', ...$values);
    }

    /** @param array<int> $values */
    private static function packInts(array $values, string $code): string
    {
        return pack($code . '*', ...$values);
    }

    /** @return list<float> */
    private static function floats(string $bytes): array
    {
        if ($bytes === '') {
            return [];
        }
        $u = unpack('g*', $bytes);

        /** @var list<float> */
        return $u === false ? [] : array_values($u);
    }

    /** @return list<float> */
    private static function sliceFloats(string $bytes, int $firstFloat, int $count): array
    {
        $u = unpack('g*', substr($bytes, $firstFloat * 4, $count * 4));

        /** @var list<float> */
        return $u === false ? [] : array_values($u);
    }

    /** @return list<int> */
    private static function ints(string $bytes, string $code): array
    {
        if ($bytes === '') {
            return [];
        }
        $u = unpack($code . '*', $bytes);

        /** @var list<int> */
        return $u === false ? [] : array_values($u);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function boundsOfPacked(string $positions, int $vertexCount): array
    {
        if ($vertexCount === 0) {
            return [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        }
        $minX = $minY = $minZ = INF;
        $maxX = $maxY = $maxZ = -INF;
        $chunkVertices = 8192;
        for ($start = 0; $start < $vertexCount; $start += $chunkVertices) {
            $count = min($chunkVertices, $vertexCount - $start);
            $f = self::sliceFloats($positions, $start * 3, $count * 3);
            for ($i = 0, $n = $count * 3; $i < $n; $i += 3) {
                $x = $f[$i];
                $y = $f[$i + 1];
                $z = $f[$i + 2];
                if ($x < $minX) { $minX = $x; }
                if ($x > $maxX) { $maxX = $x; }
                if ($y < $minY) { $minY = $y; }
                if ($y > $maxY) { $maxY = $y; }
                if ($z < $minZ) { $minZ = $z; }
                if ($z > $maxZ) { $maxZ = $z; }
            }
        }

        return [$minX, $minY, $minZ, $maxX, $maxY, $maxZ];
    }
}
