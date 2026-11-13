<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A polygon mesh: faces of three or more vertices (quad-dominant meshes are the point), the input of
 * {@see CatmullClark}. Vertex positions are shared between faces; texture coordinates are optional and
 * live on their own vertex list with their own face indices ($faceUvs), so a UV seam is simply two faces that
 * share a position but not a UV vertex. Edges may carry a crease sharpness (0 = smooth, 1 = one level of
 * subdivision stays sharp, {@see INF} = always sharp: lid margins, nails, fingertips).
 *
 * Immutable data; every method is pure.
 */
final readonly class PolyMesh
{
    /**
     * @param array<float> $positions x, y, z per vertex
     * @param list<list<int>>   $faces     vertex indices per face, counter-clockwise seen from outside
     * @param list<float>|null  $uvs       u, v per UV vertex (null = no texture coordinates)
     * @param list<list<int>>|null $faceUvs UV vertex index per face corner, same shape as $faces
     * @param array<string, float> $creases edge key (see {@see edgeKey()}) => sharpness
     */
    public function __construct(
        public array $positions,
        public array $faces,
        public ?array $uvs = null,
        public ?array $faceUvs = null,
        public array $creases = [],
    ) {
        if (count($positions) % 3 !== 0) {
            throw new \InvalidArgumentException('PolyMesh: positions must be xyz triples');
        }
        $n = intdiv(count($positions), 3);
        foreach ($faces as $f => $face) {
            if (count($face) < 3) {
                throw new \InvalidArgumentException("PolyMesh: face {$f} has fewer than three vertices");
            }
            foreach ($face as $v) {
                if ($v < 0 || $v >= $n) {
                    throw new \InvalidArgumentException("PolyMesh: face {$f} uses vertex {$v} outside the mesh");
                }
            }
        }
        if (($uvs === null) !== ($faceUvs === null)) {
            throw new \InvalidArgumentException('PolyMesh: uvs and faceUvs go together');
        }
        if ($faceUvs !== null) {
            if (count($faceUvs) !== count($faces)) {
                throw new \InvalidArgumentException('PolyMesh: one UV face per face');
            }
            $nu = intdiv(count((array) $uvs), 2);
            foreach ($faceUvs as $f => $uf) {
                if (count($uf) !== count($faces[$f])) {
                    throw new \InvalidArgumentException("PolyMesh: UV face {$f} has another size than its face");
                }
                foreach ($uf as $u) {
                    if ($u < 0 || $u >= $nu) {
                        throw new \InvalidArgumentException("PolyMesh: UV face {$f} uses UV vertex {$u} outside the list");
                    }
                }
            }
        }
    }

    public function vertexCount(): int
    {
        return intdiv(count($this->positions), 3);
    }

    public function faceCount(): int
    {
        return count($this->faces);
    }

    /** Faces with exactly four vertices. */
    public function quadCount(): int
    {
        $q = 0;
        foreach ($this->faces as $f) {
            if (count($f) === 4) {
                $q++;
            }
        }

        return $q;
    }

    /** Key of the undirected edge a-b used by $creases and {@see MeshAdjacency}. */
    public static function edgeKey(int $a, int $b): string
    {
        return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}";
    }

    /**
     * This mesh with crease sharpness set on edges (merged over the ones it has).
     *
     * @param array<string, float> $creases
     */
    public function withCreases(array $creases): self
    {
        return new self($this->positions, $this->faces, $this->uvs, $this->faceUvs, $creases + $this->creases);
    }

    /**
     * This mesh with other positions of the same topology (a warped or morphed template).
     *
     * @param array<float> $positions
     */
    public function withPositions(array $positions): self
    {
        if (count($positions) !== count($this->positions)) {
            throw new \InvalidArgumentException('PolyMesh::withPositions: the vertex count must not change');
        }

        return new self($positions, $this->faces, $this->uvs, $this->faceUvs, $this->creases);
    }

    /** Triangles of the faces (a fan from each face's first vertex), as a plain {@see MeshData} without normals. */
    public function toTriangles(): MeshData
    {
        $idx = [];
        foreach ($this->faces as $face) {
            for ($i = 1, $n = count($face) - 1; $i < $n; $i++) {
                array_push($idx, $face[0], $face[$i], $face[$i + 1]);
            }
        }

        return new MeshData($this->positions, [], [], $idx);
    }
}
