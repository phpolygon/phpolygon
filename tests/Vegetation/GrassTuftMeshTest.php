<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Rendering\Quality\GrassQuality;
use PHPolygon\Vegetation\GrassBladeShape;
use PHPolygon\Vegetation\GrassDetail;
use PHPolygon\Vegetation\GrassTuftMesh;

final class GrassTuftMeshTest extends TestCase
{
    /** @return iterable<string, array{GrassBladeShape}> */
    public static function shapes(): iterable
    {
        foreach ([1, 2, 3, 4, 7] as $s) {
            yield "flat {$s}" => [new GrassBladeShape(segments: $s, blades: 7)];
            yield "folded {$s}" => [new GrassBladeShape(segments: $s, blades: 5, fold: true)];
        }
    }

    #[DataProvider('shapes')]
    public function testTriangleBudgetMatchesTheShape(GrassBladeShape $shape): void
    {
        $mesh = GrassTuftMesh::generate($shape, 3);
        self::assertSame($shape->trianglesPerTuft(), intdiv(count($mesh->indices), 3));
    }

    #[DataProvider('shapes')]
    public function testWindingAgreesWithTheNormalsAndNormalsLeanUp(GrassBladeShape $shape): void
    {
        $mesh = GrassTuftMesh::generate($shape, 5);
        $v = $mesh->vertices;
        $n = $mesh->normals;
        for ($i = 0, $c = count($mesh->indices); $i < $c; $i += 3) {
            [$a, $b, $d] = [$mesh->indices[$i], $mesh->indices[$i + 1], $mesh->indices[$i + 2]];
            $e1 = [$v[$b * 3] - $v[$a * 3], $v[$b * 3 + 1] - $v[$a * 3 + 1], $v[$b * 3 + 2] - $v[$a * 3 + 2]];
            $e2 = [$v[$d * 3] - $v[$a * 3], $v[$d * 3 + 1] - $v[$a * 3 + 1], $v[$d * 3 + 2] - $v[$a * 3 + 2]];
            $cr = [$e1[1] * $e2[2] - $e1[2] * $e2[1], $e1[2] * $e2[0] - $e1[0] * $e2[2], $e1[0] * $e2[1] - $e1[1] * $e2[0]];
            $avg = [0.0, 0.0, 0.0];
            foreach ([$a, $b, $d] as $k) {
                $avg[0] += $n[$k * 3];
                $avg[1] += $n[$k * 3 + 1];
                $avg[2] += $n[$k * 3 + 2];
            }
            self::assertGreaterThanOrEqual(0.0, $cr[0] * $avg[0] + $cr[1] * $avg[1] + $cr[2] * $avg[2], "triangle {$i} winds against its normals");
        }
        for ($k = 0, $c = count($n); $k < $c; $k += 3) {
            self::assertEqualsWithDelta(1.0, sqrt($n[$k] ** 2 + $n[$k + 1] ** 2 + $n[$k + 2] ** 2), 1e-6);
            self::assertGreaterThan(0.3, $n[$k + 1], 'normals lean toward world up (calm shading)');
        }
    }

    public function testUvCarriesBladeRandomAndHeightAlongTheBlade(): void
    {
        $shape = new GrassBladeShape(segments: 4, blades: 6);
        $mesh = GrassTuftMesh::generate($shape, 9);
        $perBlade = intdiv($mesh->vertexCount(), 6);
        for ($b = 0; $b < 6; $b++) {
            $rand = $mesh->uvs[$b * $perBlade * 2];
            $lastT = -1.0;
            $lastY = -1.0;
            for ($k = 0; $k < $perBlade; $k++) {
                $i = $b * $perBlade + $k;
                self::assertSame($rand, $mesh->uvs[$i * 2], 'one random per blade');
                self::assertGreaterThanOrEqual(0.0, $rand);
                self::assertLessThan(1.0, $rand);
                $t = $mesh->uvs[$i * 2 + 1];
                self::assertGreaterThanOrEqual($lastT, $t);
                $lastT = $t;
                if ($t === 0.0) {
                    self::assertEqualsWithDelta(0.0, $mesh->vertices[$i * 3 + 1], 1e-9, 'roots on y = 0');
                }
                $lastY = max($lastY, $mesh->vertices[$i * 3 + 1]);
            }
            self::assertSame(1.0, $lastT, 'the tip has t = 1');
            self::assertGreaterThanOrEqual($shape->heightMin * 0.8, $lastY);
            self::assertLessThanOrEqual($shape->heightMax, $lastY + 1e-9);
        }
    }

    public function testDeterministicPerSeed(): void
    {
        $shape = new GrassBladeShape();
        self::assertEquals(GrassTuftMesh::generate($shape, 4), GrassTuftMesh::generate($shape, 4));
        self::assertNotEquals(GrassTuftMesh::generate($shape, 4)->vertices, GrassTuftMesh::generate($shape, 5)->vertices);
    }

    public function testCoarseLodKeepsRootsAndTipsOfTheFineOne(): void
    {
        $fine = new GrassBladeShape(segments: 7, blades: 8);
        $coarse = $fine->withSegments(2);
        $a = self::rootsAndTips(GrassTuftMesh::generate($fine, 11));
        $b = self::rootsAndTips(GrassTuftMesh::generate($coarse, 11));
        self::assertCount(8, $a['tips']);
        foreach ($a['tips'] as $i => $tip) {
            self::assertEqualsWithDelta($tip, $b['tips'][$i], 1e-9, 'same tip in both LODs');
        }
        foreach ($a['roots'] as $i => $root) {
            self::assertEqualsWithDelta($root, $b['roots'][$i], 1e-9, 'same root in both LODs');
        }
    }

    public function testQualityLevelsResolveFinerWithTheSameFormLanguage(): void
    {
        self::assertNull(GrassQuality::Off->detail());
        $order = [GrassQuality::Low, GrassQuality::Medium, GrassQuality::High, GrassQuality::Ultra];
        $prev = null;
        foreach ($order as $q) {
            $d = $q->detail();
            self::assertInstanceOf(GrassDetail::class, $d);
            self::assertGreaterThan(0, $d->colorBands, "{$q->value} is stylised (flat colour bands)");
            self::assertFalse($d->shape->fold);
            if ($prev !== null) {
                self::assertGreaterThan($prev->shape->segments, $d->shape->segments, $q->value);
                self::assertGreaterThan($prev->tuftsPerSquareMetre, $d->tuftsPerSquareMetre, $q->value);
                self::assertGreaterThan($prev->fadeRange, $d->fadeRange, $q->value);
                self::assertGreaterThanOrEqual($prev->shape->trianglesPerTuft(), $d->shape->trianglesPerTuft());
            }
            $prev = $d;
        }
        $medium = GrassQuality::Medium->detail();
        self::assertNotNull($medium);
        self::assertEquals(new GrassDetail(), $medium, 'the default parameter set is medium');
        $real = GrassQuality::Realistic->detail();
        self::assertNotNull($real);
        self::assertTrue($real->shape->fold);
        self::assertSame(0, $real->colorBands, 'realistic: smooth gradient');
        self::assertLessThan($medium->shape->width, $real->shape->width, 'realistic: thinner blades');
        self::assertGreaterThan($medium->translucency, $real->translucency);
        self::assertGreaterThan($medium->shape->blades, $real->shape->blades);
    }

    /** @return array{roots: list<list<float>>, tips: list<list<float>>} */
    private static function rootsAndTips(MeshData $mesh): array
    {
        $roots = [];
        $tips = [];
        $sum = [];
        for ($i = 0, $n = $mesh->vertexCount(); $i < $n; $i++) {
            $t = $mesh->uvs[$i * 2 + 1];
            $p = [$mesh->vertices[$i * 3], $mesh->vertices[$i * 3 + 1], $mesh->vertices[$i * 3 + 2]];
            if ($t === 1.0) {
                $tips[] = $p;
            } elseif ($t === 0.0) {
                $sum[] = $p;
                if (count($sum) === 2) {
                    $roots[] = [($sum[0][0] + $sum[1][0]) / 2, 0.0, ($sum[0][2] + $sum[1][2]) / 2];
                    $sum = [];
                }
            }
        }
        return ['roots' => $roots, 'tips' => $tips];
    }
}
