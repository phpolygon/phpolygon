<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Adjacency of a polygon mesh: which faces share an edge, how many edges meet at a vertex (valence), the boundary
 * loops of an open mesh, and whether the mesh is a closed manifold. Built once from the faces; every query is a
 * lookup. Also pairs the triangles of a triangle mesh into quads where two of them form one
 * ({@see quadsFromTriangles()}), for meshes that arrive triangulated.
 *
 * Pure and immutable after construction: safe to share between threads.
 */
final class MeshAdjacency
{
    /** @var array<string, list<int>> edge key => faces */
    private array $edgeFaces = [];
    /** @var array<int, list<int>> vertex => faces around it */
    private array $vertexFaces = [];
    /** @var array<int, array<int, true>> vertex => neighbouring vertices */
    private array $neighbours = [];

    /** @param list<list<int>> $faces */
    public function __construct(private readonly array $faces)
    {
        foreach ($faces as $f => $face) {
            $n = count($face);
            for ($i = 0; $i < $n; $i++) {
                $a = $face[$i];
                $b = $face[($i + 1) % $n];
                $this->edgeFaces[PolyMesh::edgeKey($a, $b)][] = $f;
                $this->vertexFaces[$a][] = $f;
                $this->neighbours[$a][$b] = true;
                $this->neighbours[$b][$a] = true;
            }
        }
    }

    public static function of(PolyMesh $mesh): self
    {
        return new self($mesh->faces);
    }

    /** @return array<string, list<int>> every edge key with the faces that use it */
    public function edgeFaces(): array
    {
        return $this->edgeFaces;
    }

    /** @return list<int> the faces using the edge a-b (empty when there is none) */
    public function facesOfEdge(int $a, int $b): array
    {
        return $this->edgeFaces[PolyMesh::edgeKey($a, $b)] ?? [];
    }

    /** @return list<int> the faces around a vertex, in face order */
    public function facesOfVertex(int $v): array
    {
        return $this->vertexFaces[$v] ?? [];
    }

    /** Number of edges that meet at the vertex. */
    public function valence(int $v): int
    {
        return count($this->neighbours[$v] ?? []);
    }

    /** @return list<int> the vertices joined to $v by an edge */
    public function neighbours(int $v): array
    {
        return array_keys($this->neighbours[$v] ?? []);
    }

    /** @return list<array{0: int, 1: int}> edges used by exactly one face (a, b in the face's winding) */
    public function boundaryEdges(): array
    {
        $out = [];
        foreach ($this->edgeFaces as $key => $faces) {
            if (count($faces) !== 1) {
                continue;
            }
            $face = $this->faces[$faces[0]];
            $n = count($face);
            [$ka, $kb] = array_map('intval', explode(':', $key));
            for ($i = 0; $i < $n; $i++) {
                $a = $face[$i];
                $b = $face[($i + 1) % $n];
                if (($a === $ka && $b === $kb) || ($a === $kb && $b === $ka)) {
                    $out[] = [$a, $b];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The boundary of an open mesh as closed loops of vertices (empty for a closed mesh). A vertex where several loops
     * touch is followed greedily; non-manifold input may give loops that share vertices.
     *
     * @return list<list<int>>
     */
    public function boundaryLoops(): array
    {
        $next = [];
        foreach ($this->boundaryEdges() as [$a, $b]) {
            $next[$a][] = $b;
        }
        $loops = [];
        while ($next !== []) {
            $start = array_key_first($next);
            $loop = [$start];
            $v = $start;
            while (true) {
                $targets = $next[$v] ?? [];
                if ($targets === []) {
                    break;
                }
                $n = array_shift($targets);
                if ($targets === []) {
                    unset($next[$v]);
                } else {
                    $next[$v] = $targets;
                }
                if ($n === $start) {
                    break;
                }
                $loop[] = $n;
                $v = $n;
            }
            $loops[] = $loop;
        }

        return $loops;
    }

    /** @return list<string> edge keys used by more than two faces */
    public function nonManifoldEdges(): array
    {
        $out = [];
        foreach ($this->edgeFaces as $key => $faces) {
            if (count($faces) > 2) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** True when every edge is shared by exactly two faces (no boundary, no non-manifold edge). */
    public function isClosedManifold(): bool
    {
        foreach ($this->edgeFaces as $faces) {
            if (count($faces) !== 2) {
                return false;
            }
        }

        return true;
    }

    /** Faces with four vertices over all faces: 1.0 = pure quad mesh. */
    public function quadRatio(): float
    {
        $q = 0;
        foreach ($this->faces as $f) {
            if (count($f) === 4) {
                $q++;
            }
        }

        return $this->faces === [] ? 0.0 : $q / count($this->faces);
    }

    /**
     * Quads out of a triangle mesh: two triangles that share an edge are merged into a quad when the quad they form is
     * nearly planar and convex (the angle between the two triangle normals is below $maxAngleDegrees). Greedy in
     * triangle order, the pairing across the shortest-diagonal-first choice that keeps the quad most regular; a triangle
     * that finds no partner stays a triangle. The vertices are the mesh's own (welded by index); weld first
     * ({@see MeshCleanup}) when the triangles do not share vertices.
     */
    public static function quadsFromTriangles(MeshData $mesh, float $maxAngleDegrees = 25.0): PolyMesh
    {
        $tris = [];
        for ($i = 0, $n = count($mesh->indices) - 2; $i < $n; $i += 3) {
            $tris[] = [(int) $mesh->indices[$i], (int) $mesh->indices[$i + 1], (int) $mesh->indices[$i + 2]];
        }
        $edgeTris = [];
        foreach ($tris as $t => $tri) {
            for ($e = 0; $e < 3; $e++) {
                $edgeTris[PolyMesh::edgeKey($tri[$e], $tri[($e + 1) % 3])][] = $t;
            }
        }
        $cos = cos(deg2rad($maxAngleDegrees));
        // Candidate pairs: (t, u, shared edge), scored by the shortest diagonal's relation to the edge (a square has diagonal = sqrt2 * side).
        $cands = [];
        foreach ($edgeTris as $key => $ts) {
            if (count($ts) !== 2) {
                continue;
            }
            [$t, $u] = $ts;
            [$ea, $eb] = array_map('intval', explode(':', $key));
            $n1 = self::triNormal($mesh, $tris[$t]);
            $n2 = self::triNormal($mesh, $tris[$u]);
            if ($n1[0] * $n2[0] + $n1[1] * $n2[1] + $n1[2] * $n2[2] < $cos) {
                continue;
            }
            $oppT = array_values(array_diff($tris[$t], [$ea, $eb]))[0] ?? null;
            $oppU = array_values(array_diff($tris[$u], [$ea, $eb]))[0] ?? null;
            if ($oppT === null || $oppU === null) {
                continue;
            }
            $shared = self::dist($mesh, $ea, $eb);
            $other = self::dist($mesh, $oppT, $oppU);
            // A good quad has the new diagonal (oppT-oppU) about as long as the old one (ea-eb); the edge is cut.
            $cands[] = [abs(log(max($shared, 1e-9) / max($other, 1e-9))), $t, $u, $ea, $eb, $oppT, $oppU];
        }
        usort($cands, static fn (array $x, array $y): int => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $used = [];
        $quads = [];
        foreach ($cands as [, $t, $u, $ea, $eb, $oppT, $oppU]) {
            if (isset($used[$t]) || isset($used[$u])) {
                continue;
            }
            // Winding: walk triangle t from ea to eb, so the quad is oppT, ea, oppU, eb in t's orientation.
            $tri = $tris[$t];
            $pos = array_search($ea, $tri, true);
            $forward = $tri[($pos + 1) % 3] === $eb;
            $quad = $forward ? [$oppT, $ea, $oppU, $eb] : [$oppT, $eb, $oppU, $ea];
            // $forward: t runs ea -> eb, then oppT closes: order oppT, ea, (oppU across), eb.
            $used[$t] = $used[$u] = true;
            $quads[$t] = $quad;
        }
        $faces = [];
        foreach ($tris as $t => $tri) {
            if (isset($quads[$t])) {
                $faces[] = $quads[$t];
            } elseif (!isset($used[$t])) {
                $faces[] = $tri;
            }
        }

        return new PolyMesh($mesh->vertices, $faces);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $tri
     * @return array{0: float, 1: float, 2: float}
     */
    private static function triNormal(MeshData $mesh, array $tri): array
    {
        [$a, $b, $c] = $tri;
        $p = $mesh->vertices;
        $u = [$p[$b * 3] - $p[$a * 3], $p[$b * 3 + 1] - $p[$a * 3 + 1], $p[$b * 3 + 2] - $p[$a * 3 + 2]];
        $v = [$p[$c * 3] - $p[$a * 3], $p[$c * 3 + 1] - $p[$a * 3 + 1], $p[$c * 3 + 2] - $p[$a * 3 + 2]];
        $x = $u[1] * $v[2] - $u[2] * $v[1];
        $y = $u[2] * $v[0] - $u[0] * $v[2];
        $z = $u[0] * $v[1] - $u[1] * $v[0];
        $l = sqrt($x * $x + $y * $y + $z * $z);

        return $l > 1e-20 ? [$x / $l, $y / $l, $z / $l] : [0.0, 0.0, 0.0];
    }

    private static function dist(MeshData $mesh, int $a, int $b): float
    {
        $p = $mesh->vertices;

        return sqrt(($p[$a * 3] - $p[$b * 3]) ** 2 + ($p[$a * 3 + 1] - $p[$b * 3 + 1]) ** 2 + ($p[$a * 3 + 2] - $p[$b * 3 + 2]) ** 2);
    }
}
