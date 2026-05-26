<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Prototype;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshCacheIO;
use PHPolygon\Math\Vec3;
use PHPolygon\Prototype\PrototypeExporter;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;

class PrototypeExporterTest extends TestCase
{
    private string $outDir = '';

    protected function setUp(): void
    {
        $this->outDir = sys_get_temp_dir() . '/phpolygon-proto-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->outDir !== '' && is_dir($this->outDir)) {
            $this->removeDir($this->outDir);
        }
    }

    public function testExportWritesCompleteBundle(): void
    {
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $manifest = (new PrototypeExporter())->export(
            $this->outDir,
            [Transform3D::class, MeshRenderer::class],
            ['box_1x1x1' => $box],
            ['stone' => Material::color(new Color(0.5, 0.25, 0.125))],
            [$this->sampleScene()],
        );

        // Manifest written and self-consistent.
        $this->assertFileExists($this->outDir . '/manifest.json');
        $this->assertSame('meshcache/v1', $manifest['meshFormat']);
        $onDisk = json_decode((string) file_get_contents($this->outDir . '/manifest.json'), true);
        $this->assertEquals($manifest, $onDisk);

        // Schema present with the components we passed.
        $schema = json_decode((string) file_get_contents($this->outDir . '/schema.json'), true);
        $this->assertArrayHasKey('Transform3D', $schema['components']);
        $this->assertArrayHasKey('MeshRenderer', $schema['components']);
    }

    public function testMeshBinaryDecodesBackToEquivalentGeometry(): void
    {
        $box = BoxMesh::generate(2.0, 1.0, 1.0);
        $manifest = (new PrototypeExporter())->export(
            $this->outDir,
            [],
            ['crate' => $box],
            [],
        );

        $entry = $manifest['meshes']['crate'];
        $this->assertSame($box->vertexCount(), $entry['vertexCount']);
        $this->assertSame($box->triangleCount(), $entry['triangleCount']);

        $decoded = MeshCacheIO::decode((string) file_get_contents($this->outDir . '/' . $entry['file']));
        $this->assertNotNull($decoded);
        $this->assertSame($box->vertexCount(), $decoded->vertexCount());
        $this->assertSame($box->indices, $decoded->indices);
        // float32 storage: positions/normals round-trip within single-precision.
        $this->assertEqualsWithDelta($box->vertices, $decoded->vertices, 1e-5);
    }

    public function testMaterialToArrayMapsColorsAndScalars(): void
    {
        $material = Material::carpaint(new Color(0.8, 0.1, 0.1), metallic: 0.7);
        $array = PrototypeExporter::materialToArray($material);

        $this->assertSame(['r' => 0.8, 'g' => 0.1, 'b' => 0.1, 'a' => 1.0], $array['albedo']);
        $this->assertEqualsWithDelta(0.7, $array['metallic'], 1e-6);
        $this->assertArrayHasKey('roughness', $array);
        $this->assertArrayHasKey('clearcoat', $array);
        $this->assertSame('default', $array['shader']);
    }

    public function testSceneExportRoundTripsName(): void
    {
        $manifest = (new PrototypeExporter())->export(
            $this->outDir,
            [],
            [],
            [],
            [$this->sampleScene()],
        );

        $relative = $manifest['scenes']['test_scene'];
        $this->assertFileExists($this->outDir . '/' . $relative);

        $scene = json_decode((string) file_get_contents($this->outDir . '/' . $relative), true);
        $this->assertSame('test_scene', $scene['name']);
        $this->assertNotEmpty($scene['entities']);
        $this->assertSame('Box', $scene['entities'][0]['name']);
    }

    private function sampleScene(): Scene
    {
        return new class extends Scene {
            public function getName(): string
            {
                return 'test_scene';
            }

            public function build(SceneBuilder $builder): void
            {
                $builder->entity('Box')
                    ->with(new Transform3D(position: new Vec3(1, 2, 3)))
                    ->with(new MeshRenderer('box_1x1x1', 'stone'));
            }
        };
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
