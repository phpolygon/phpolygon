<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\MeshSimplifier;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Hlod\HlodBuilder;

/** HLOD building blocks (shader plan 9.1): placing, simplifying, merging per material. */
final class HlodBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        MeshRegistry::clear();
    }

    public function testTransformedPlacesPositionsAndTurnsNormals(): void
    {
        $box = BoxMesh::generate(2.0, 1.0, 1.0);
        $placed = $box->transformed(Mat4::translation(5.0, 0.0, 0.0)->multiply(Mat4::rotationY(M_PI / 2))->multiply(Mat4::scaling(1.0, 1.0, 3.0)));
        $xs = [];
        $zs = [];
        for ($i = 0; $i < count($placed->vertices); $i += 3) {
            $xs[] = $placed->vertices[$i];
            $zs[] = $placed->vertices[$i + 2];
        }
        // 2 m along x, turned a quarter: along z; z scaled by 3 first: 3 m along x.
        self::assertEqualsWithDelta(3.5, min($xs), 1e-6);
        self::assertEqualsWithDelta(6.5, max($xs), 1e-6);
        self::assertEqualsWithDelta(2.0, max($zs) - min($zs), 1e-6);
        for ($i = 0; $i < count($placed->normals); $i += 3) {
            $len = sqrt($placed->normals[$i] ** 2 + $placed->normals[$i + 1] ** 2 + $placed->normals[$i + 2] ** 2);
            self::assertEqualsWithDelta(1.0, $len, 1e-6);
        }
        // A face normal follows the rotation: +X turns into -Z.
        $found = false;
        for ($i = 0; $i < count($box->normals); $i += 3) {
            if ($box->normals[$i] > 0.9) {
                self::assertEqualsWithDelta(-1.0, $placed->normals[$i + 2], 1e-6);
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function testTheCacheGivesBackTheSameCell(): void
    {
        MeshRegistry::register('cache_sphere', SphereMesh::generate(1.0, 24, 16));
        $draws = [];
        for ($i = 0; $i < 4; $i++) {
            $draws[] = ['cache_sphere', $i % 2 === 0 ? 'stone' : 'roof', Mat4::translation($i * 3.0, 0.0, 0.0)];
        }
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpolygon_hlod_test_' . getmypid();
        $path = HlodBuilder::cachePath('district', $draws, 0.5, $dir);
        @unlink($path);
        try {
            $built = HlodBuilder::buildCached('district', $draws, 0.5, 100.0, $dir);
            self::assertFileExists($path);
            $meshes = [];
            foreach ($built->proxies as [$meshId]) {
                $meshes[$meshId] = MeshRegistry::get($meshId);
            }
            MeshRegistry::clear();
            // From the file alone: no source mesh registered any more.
            $read = HlodBuilder::buildCached('district', $draws, 0.5, 100.0, $dir);
            self::assertSame($built->proxies, $read->proxies);
            self::assertSame([$built->sourceTriangles, $built->proxyTriangles], [$read->sourceTriangles, $read->proxyTriangles]);
            self::assertEqualsWithDelta($built->radius, $read->radius, 1e-9);
            self::assertEqualsWithDelta(0.0, $built->center->sub($read->center)->length(), 1e-9);
            foreach ($meshes as $meshId => $mesh) {
                $again = MeshRegistry::get($meshId);
                self::assertNotNull($again);
                self::assertSame($mesh?->indices, $again->indices);
                // Stored as 32-bit floats, as the GPU reads them.
                self::assertCount(count($mesh?->vertices ?? []), $again->vertices);
                foreach ($again->vertices as $k => $v) {
                    self::assertEqualsWithDelta($mesh?->vertices[$k], $v, 1e-5);
                }
            }
            // A moved draw is another cell.
            $draws[0][2] = Mat4::translation(-1.0, 0.0, 0.0);
            self::assertNotSame($path, HlodBuilder::cachePath('district', $draws, 0.5, $dir));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testAMirroringPlacementKeepsTheWindingOutward(): void
    {
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $mirrored = $box->transformed(Mat4::scaling(-1.0, 1.0, 1.0));
        self::assertSame($box->indices[0], $mirrored->indices[0]);
        self::assertSame($box->indices[1], $mirrored->indices[2]);
        self::assertSame($box->indices[2], $mirrored->indices[1]);
    }

    public function testClusteringThinsAFineSphereAndKeepsItsSize(): void
    {
        $sphere = SphereMesh::generate(1.0, 48, 64);
        $coarse = MeshSimplifier::cluster($sphere, 0.25);
        self::assertLessThan($sphere->triangleCount() / 4, $coarse->triangleCount());
        self::assertGreaterThan(50, $coarse->triangleCount());
        $max = 0.0;
        for ($i = 0; $i < count($coarse->vertices); $i += 3) {
            $max = max($max, sqrt($coarse->vertices[$i] ** 2 + $coarse->vertices[$i + 1] ** 2 + $coarse->vertices[$i + 2] ** 2));
        }
        self::assertEqualsWithDelta(1.0, $max, 0.15);
    }

    public function testClusteringKeepsTheFacesOfABox(): void
    {
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        self::assertSame(12, MeshSimplifier::cluster($box, 0.1)->triangleCount());
    }

    public function testACellMergesPerMaterialAndSwitchesBeyondItsDistance(): void
    {
        MeshRegistry::register('ball', SphereMesh::generate(0.4, 24, 32));
        MeshRegistry::register('crate', BoxMesh::generate(1.0, 1.0, 1.0));
        $draws = [];
        for ($x = 0; $x < 10; $x++) {
            for ($z = 0; $z < 10; $z++) {
                $draws[] = ['ball', 'red', Mat4::translation($x * 2.0, 0.4, $z * 2.0)];
                $draws[] = ['crate', 'wood', Mat4::translation($x * 2.0 + 1.0, 0.5, $z * 2.0)];
            }
        }
        $cell = HlodBuilder::build('block7', $draws, 0.3, 40.0);
        self::assertCount(2, $cell->proxies);
        self::assertSame(['hlod/block7/red', 'red'], $cell->proxies[0]);
        self::assertTrue(MeshRegistry::has('hlod/block7/wood'));
        self::assertLessThan($cell->sourceTriangles / 3, $cell->proxyTriangles);
        self::assertEqualsWithDelta(9.5, $cell->center->x, 0.6);
        self::assertFalse($cell->useProxy(new Vec3(10.0, 2.0, 10.0)));
        self::assertTrue($cell->useProxy(new Vec3(10.0, 2.0, 10.0 + $cell->radius + 41.0)));
        self::assertCount(2, $cell->proxyDraws());
    }
}
