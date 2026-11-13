<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\CatmullClark;
use PHPolygon\Geometry\MeshAdjacency;
use PHPolygon\Geometry\MeshCleanup;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\PolyMesh;
use PHPUnit\Framework\TestCase;

final class CatmullClarkTest extends TestCase
{
    /** A cube of side 2 around the origin as six outward-facing quads. */
    private static function cube(bool $uvs = false, float $crease = 0.0): PolyMesh
    {
        $p = [-1, -1, -1,  1, -1, -1,  1, 1, -1,  -1, 1, -1,  -1, -1, 1,  1, -1, 1,  1, 1, 1,  -1, 1, 1];
        $faces = [[0, 3, 2, 1], [4, 5, 6, 7], [0, 1, 5, 4], [2, 3, 7, 6], [1, 2, 6, 5], [3, 0, 4, 7]];
        $uv = null;
        $fuv = null;
        if ($uvs) {
            $uv = [];
            $fuv = [];
            foreach ($faces as $f => $_) {
                $fuv[] = [$f * 4, $f * 4 + 1, $f * 4 + 2, $f * 4 + 3];
                array_push($uv, 0.0, 0.0, 1.0, 0.0, 1.0, 1.0, 0.0, 1.0);
            }
        }
        $creases = [];
        if ($crease > 0.0) {
            foreach ($faces as $face) {
                for ($i = 0; $i < 4; $i++) {
                    $creases[PolyMesh::edgeKey($face[$i], $face[($i + 1) % 4])] = $crease;
                }
            }
        }

        return new PolyMesh(array_map('floatval', $p), $faces, $uv, $fuv, $creases);
    }

    private static function grid(int $n): PolyMesh
    {
        $p = [];
        for ($y = 0; $y <= $n; $y++) {
            for ($x = 0; $x <= $n; $x++) {
                array_push($p, (float) $x, (float) $y, 0.0);
            }
        }
        $faces = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $a = $y * ($n + 1) + $x;
                $faces[] = [$a, $a + 1, $a + $n + 2, $a + $n + 1];
            }
        }

        return new PolyMesh($p, $faces);
    }

    public function testACubeSubdividesToAClosedManifoldWithTheRightCounts(): void
    {
        $r1 = CatmullClark::subdivide(self::cube(), 1);
        self::assertSame(26, $r1->vertexCount);
        self::assertSame(24 * 2, $r1->mesh->triangleCount());
        $r2 = CatmullClark::subdivide(self::cube(), 2);
        self::assertSame(98, $r2->vertexCount);
        self::assertSame(96 * 2, $r2->mesh->triangleCount());
        $t = MeshCleanup::topology($r2->mesh);
        self::assertSame(['openEdges' => 0, 'nonManifoldEdges' => 0, 'flippedEdges' => 0, 'degenerateTriangles' => 0], $t);
        // It rounds off: every vertex lies inside the cube, the corners are cut.
        for ($i = 0; $i < $r2->vertexCount; $i++) {
            $r = $r2->mesh->vertices;
            self::assertLessThanOrEqual(1.0 + 1e-9, max(abs($r[$i * 3]), abs($r[$i * 3 + 1]), abs($r[$i * 3 + 2])));
        }
        // Normals point outward.
        $m = $r2->mesh;
        for ($i = 0; $i < $m->vertexCount(); $i++) {
            $dot = $m->normals[$i * 3] * $m->vertices[$i * 3] + $m->normals[$i * 3 + 1] * $m->vertices[$i * 3 + 1] + $m->normals[$i * 3 + 2] * $m->vertices[$i * 3 + 2];
            self::assertGreaterThan(0.0, $dot);
        }
    }

    public function testStencilsSumToOneAndCarryBaseAttributes(): void
    {
        $r = CatmullClark::subdivide(self::cube(), 2);
        for ($v = 0; $v < $r->vertexCount; $v++) {
            self::assertEqualsWithDelta(1.0, array_sum($r->stencilWeight[$v]), 1e-12);
        }
        // The positions are the stencil combination of the base positions.
        $base = self::cube()->positions;
        $again = $r->positions($base);
        foreach ($r->outputToVertex as $o => $v) {
            self::assertEqualsWithDelta($r->mesh->vertices[$o * 3 + 1], $again[$v * 3 + 1], 1e-9);
        }
        // A scaled base re-smooths to a scaled surface without subdividing again.
        $scaled = $r->positions(array_map(static fn (float $x): float => $x * 3.0, $base));
        self::assertEqualsWithDelta($again[10] * 3.0, $scaled[10], 1e-9);
        // A per-base-vertex attribute (a region label 0/1) interpolates between 0 and 1.
        $label = [1.0, 1.0, 0.0, 0.0, 1.0, 1.0, 0.0, 0.0];
        $out = $r->interpolate($label, 1);
        self::assertCount($r->vertexCount, $out);
        self::assertGreaterThan(0.5, $out[0], 'base vertex 0 stays mostly itself');
        foreach ($out as $x) {
            self::assertGreaterThanOrEqual(-1e-12, $x);
            self::assertLessThanOrEqual(1.0 + 1e-12, $x);
        }
    }

    public function testCreasesKeepEdgesSharpAndSplitTheNormals(): void
    {
        $smooth = CatmullClark::subdivide(self::cube(), 2);
        $hard = CatmullClark::subdivide(self::cube(crease: INF), 2);
        // All edges infinitely sharp: the subdivided cube is the cube (every point on its faces).
        $on = true;
        for ($i = 0; $i < $hard->mesh->vertexCount(); $i++) {
            $v = $hard->mesh->vertices;
            $on = $on && abs(max(abs($v[$i * 3]), abs($v[$i * 3 + 1]), abs($v[$i * 3 + 2])) - 1.0) < 1e-9;
        }
        self::assertTrue($on, 'a fully creased cube stays a cube');
        self::assertGreaterThan($smooth->mesh->vertexCount(), $hard->mesh->vertexCount(), 'hard edges split the vertices for the normals');
        // A semi-sharp crease (1) is sharp for one level only: between the two.
        $semi = CatmullClark::subdivide(self::cube(crease: 1.0), 2);
        $maxSemi = 0.0;
        $maxSmooth = 0.0;
        for ($i = 0; $i < $semi->mesh->vertexCount(); $i++) {
            $maxSemi = max($maxSemi, abs($semi->mesh->vertices[$i * 3]));
        }
        for ($i = 0; $i < $smooth->mesh->vertexCount(); $i++) {
            $maxSmooth = max($maxSmooth, abs($smooth->mesh->vertices[$i * 3]));
        }
        self::assertGreaterThanOrEqual($maxSmooth - 1e-9, $maxSemi);
        self::assertSame(0, MeshCleanup::topology($hard->mesh)['openEdges']);
    }

    public function testUvSeamsSplitTheMeshButNotTheSurface(): void
    {
        $r = CatmullClark::subdivide(self::cube(uvs: true), 2);
        $m = $r->mesh;
        self::assertGreaterThan($r->vertexCount, $m->vertexCount(), 'seams duplicate vertices');
        self::assertSame(0, MeshCleanup::topology($m)['openEdges'], 'welded by position the surface is closed');
        self::assertNotNull($m->tangents);
        self::assertCount($m->vertexCount() * 4, $m->tangents);
        for ($i = 0; $i < $m->vertexCount(); $i++) {
            $t = [$m->tangents[$i * 4], $m->tangents[$i * 4 + 1], $m->tangents[$i * 4 + 2]];
            self::assertEqualsWithDelta(1.0, sqrt($t[0] ** 2 + $t[1] ** 2 + $t[2] ** 2), 1e-6);
            self::assertEqualsWithDelta(0.0, $t[0] * $m->normals[$i * 3] + $t[1] * $m->normals[$i * 3 + 1] + $t[2] * $m->normals[$i * 3 + 2], 1e-6);
            self::assertContains($m->tangents[$i * 4 + 3], [1.0, -1.0]);
            self::assertGreaterThanOrEqual(-1e-9, $m->uvs[$i * 2]);
            self::assertLessThanOrEqual(1.0 + 1e-9, $m->uvs[$i * 2]);
        }
    }

    public function testAnOpenGridKeepsItsBoundaryAndStaysFlat(): void
    {
        $g = self::grid(3);
        $r = CatmullClark::subdivide($g, 2);
        for ($i = 0; $i < $r->vertexCount; $i++) {
            self::assertEqualsWithDelta(0.0, $r->mesh->vertices[$i * 3 + 2], 1e-12);
            self::assertGreaterThanOrEqual(-1e-9, $r->mesh->vertices[$i * 3]);
            self::assertLessThanOrEqual(3.0 + 1e-9, $r->mesh->vertices[$i * 3]);
        }
        // A corner of two boundary edges follows the cubic boundary curve (crease rule, like a rim): it moves a little inward
        // along both edges, symmetrically, and the boundary line y = 0 stays a line.
        $o = array_search(0, $r->outputToVertex, true);
        self::assertEqualsWithDelta($r->mesh->vertices[$o * 3], $r->mesh->vertices[$o * 3 + 1], 1e-12);
        self::assertLessThan(0.3, $r->mesh->vertices[$o * 3]);
        for ($i = 0; $i < $r->vertexCount; $i++) {
            $on = abs($r->mesh->vertices[array_search($i, $r->outputToVertex, true) * 3 + 1]) < 1e-12;
            if ($on) {
                self::assertGreaterThanOrEqual(-1e-12, $r->mesh->vertices[array_search($i, $r->outputToVertex, true) * 3]);
            }
        }
        self::assertSame(16 * 9 * 2, $r->mesh->triangleCount());
    }

    public function testTheResultIsDeterministicAndLevelsAreChecked(): void
    {
        $a = CatmullClark::subdivide(self::cube(uvs: true), 2);
        $b = CatmullClark::subdivide(self::cube(uvs: true), 2);
        self::assertEquals($a, $b);
        $this->expectException(\InvalidArgumentException::class);
        CatmullClark::subdivide(self::cube(), 4);
    }

    public function testAdjacencyAnswersTheTopologyQuestions(): void
    {
        $adj = MeshAdjacency::of(self::cube());
        self::assertCount(12, $adj->edgeFaces());
        self::assertSame(3, $adj->valence(0));
        self::assertTrue($adj->isClosedManifold());
        self::assertSame([], $adj->boundaryLoops());
        self::assertSame(1.0, $adj->quadRatio());
        $g = MeshAdjacency::of(self::grid(3));
        self::assertFalse($g->isClosedManifold());
        $loops = $g->boundaryLoops();
        self::assertCount(1, $loops);
        self::assertCount(12, $loops[0]);
        self::assertSame(4, $g->valence(5));
        self::assertSame(2, $g->valence(0));
        self::assertCount(2, $g->facesOfEdge(4, 5));
    }

    public function testTrianglePairsBecomeQuadsKeepingTheirWinding(): void
    {
        $quads = self::grid(4);
        $tri = $quads->toTriangles();
        $pm = MeshAdjacency::quadsFromTriangles($tri);
        self::assertGreaterThan(0.9, MeshAdjacency::of($pm)->quadRatio());
        // Same orientation: every face of the grid faces +Z (counter-clockwise).
        foreach ($pm->faces as $face) {
            $area = 0.0;
            for ($i = 0, $n = count($face); $i < $n; $i++) {
                $a = $face[$i] * 3;
                $b = $face[($i + 1) % $n] * 3;
                $area += $pm->positions[$a] * $pm->positions[$b + 1] - $pm->positions[$b] * $pm->positions[$a + 1];
            }
            self::assertGreaterThan(0.0, $area);
        }
        self::assertInstanceOf(MeshData::class, $pm->toTriangles());
    }
}
