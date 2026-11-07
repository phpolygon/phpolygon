<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A mesh cut into meshlets for the mesh-shader path (shader plan 10.1), already
 * packed as the three storage buffers the task and mesh stage read (little-endian
 * 32-bit words, see {@see MeshletBuilder}):
 *
 *  - vertices: 8 floats per mesh vertex (position, normal, uv)
 *  - meshlets: 8 words per meshlet - vertex offset, vertex count, triangle offset,
 *    triangle count (uint, offsets into indices) and the bounding sphere (centre
 *    xyz, radius as float bits)
 *  - indices: per meshlet its mesh-vertex indices (uint), then its triangles as one
 *    word each with three 8-bit local vertex indices
 */
final readonly class MeshletData
{
    public const VERTEX_FLOATS = 8;
    public const MESHLET_WORDS = 8;

    public function __construct(
        public int $count,
        public string $vertices,
        public string $meshlets,
        public string $indices,
    ) {}

    /**
     * One meshlet unpacked (tests, debugging).
     *
     * @return array{vertexOffset: int, vertexCount: int, triangleOffset: int, triangleCount: int, center: array{float, float, float}, radius: float}
     */
    public function meshlet(int $i): array
    {
        $words = substr($this->meshlets, $i * self::MESHLET_WORDS * 4, self::MESHLET_WORDS * 4);
        /** @var array<int, int> $u */
        $u = unpack('V4', $words);
        /** @var array<int, float> $f */
        $f = unpack('g4', substr($words, 16));
        return [
            'vertexOffset' => $u[1], 'vertexCount' => $u[2],
            'triangleOffset' => $u[3], 'triangleCount' => $u[4],
            'center' => [$f[1], $f[2], $f[3]], 'radius' => $f[4],
        ];
    }

    /**
     * The mesh triangles of meshlet $i as mesh-vertex index triples.
     *
     * @return list<array{int, int, int}>
     */
    public function triangles(int $i): array
    {
        $m = $this->meshlet($i);
        /** @var array<int, int> $local */
        $local = $m['vertexCount'] > 0 ? unpack('V' . $m['vertexCount'], $this->indices, $m['vertexOffset'] * 4) : [];
        /** @var array<int, int> $packed */
        $packed = $m['triangleCount'] > 0 ? unpack('V' . $m['triangleCount'], $this->indices, $m['triangleOffset'] * 4) : [];
        $out = [];
        foreach ($packed as $p) {
            $out[] = [$local[($p & 255) + 1], $local[(($p >> 8) & 255) + 1], $local[(($p >> 16) & 255) + 1]];
        }
        return $out;
    }
}
