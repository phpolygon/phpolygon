<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshAssetLoader;
use PHPolygon\Geometry\MeshRegistry;
use PHPUnit\Framework\TestCase;

class MeshAssetLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-mesh-assets-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/sub', 0755, true);
        MeshAssetLoader::$verifySources = true;
        MeshRegistry::clear();
    }

    protected function tearDown(): void
    {
        MeshAssetLoader::$verifySources = null;
        MeshRegistry::clear();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testDecodesAllThreeSourceLayouts(): void
    {
        $box = BoxMesh::generate(1.0, 2.0, 3.0);
        $arrays = ['vertices' => $box->vertices, 'normals' => $box->normals, 'uvs' => $box->uvs, 'indices' => $box->indices];

        $raw = MeshAssetLoader::decodeJson((string) json_encode(['name' => 'a', 'raw' => $arrays]));
        $top = MeshAssetLoader::decodeJson((string) json_encode(['version' => 1] + $arrays));
        $graph = MeshAssetLoader::decodeJson((string) json_encode([
            'name' => 'g',
            'nodes' => [['id' => 'b', 'type' => 'box', 'params' => ['width' => 1.0, 'height' => 2.0, 'depth' => 3.0]]],
            'output' => 'b',
        ]));

        $this->assertSame($box->indices, $raw->indices);
        $this->assertSame($box->vertexCount(), $top->vertexCount());
        $this->assertGreaterThan(0, $graph->vertexCount());
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        MeshAssetLoader::decodeJson('{nope');
    }

    public function testCompiledSiblingIsUsedWhileItMatchesTheSource(): void
    {
        $json = $this->writeAsset('crate', BoxMesh::generate(1.0, 1.0, 1.0)->vertices);
        MeshAssetLoader::compileFile($json);
        $bin = MeshAssetLoader::compiledPath($json);
        $this->assertFileExists($bin);
        $this->assertSame($this->dir . '/crate.mesh.bin', $bin);

        $this->assertNotNull(MeshAssetLoader::compiledFor($json));
        $this->assertSame(24, MeshAssetLoader::loadFile($json)->vertexCount());
    }

    public function testStaleCompiledSiblingIsIgnoredWhenVerifying(): void
    {
        $json = $this->writeAsset('crate', BoxMesh::generate(1.0, 1.0, 1.0)->vertices);
        MeshAssetLoader::compileFile($json);
        // Edited in the editor after the compile: a smaller mesh.
        $this->writeAsset('crate', [0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0], [0, 1, 2]);

        $this->assertNull(MeshAssetLoader::compiledFor($json));
        $this->assertSame(3, MeshAssetLoader::loadFile($json)->vertexCount());

        MeshAssetLoader::$verifySources = false;
        $this->assertSame(24, MeshAssetLoader::loadFile($json)->vertexCount());
    }

    public function testCompileDirectoryIsRecursiveAndCanDropSources(): void
    {
        $this->writeAsset('a', BoxMesh::generate(1.0, 1.0, 1.0)->vertices);
        $this->writeAsset('sub/b', BoxMesh::generate(2.0, 1.0, 1.0)->vertices);
        file_put_contents($this->dir . '/notes.json', '{}');

        $stats = MeshAssetLoader::compileDirectory($this->dir, 6, removeSources: true);

        $this->assertSame(2, $stats['files']);
        $this->assertLessThan($stats['sourceBytes'], $stats['compiledBytes']);
        $this->assertFileDoesNotExist($this->dir . '/a.mesh.json');
        $this->assertFileExists($this->dir . '/a.mesh.bin');
        $this->assertFileExists($this->dir . '/sub/b.mesh.bin');
        $this->assertFileExists($this->dir . '/notes.json');
        $this->assertSame(24, MeshAssetLoader::loadFile($this->dir . '/sub/b.mesh.bin')->vertexCount());
    }

    public function testRegisterAllFindsSourceAndCompiledOnlyAssets(): void
    {
        $this->writeAsset('json_only', BoxMesh::generate(1.0, 1.0, 1.0)->vertices);
        $compiled = $this->writeAsset('bin_only', BoxMesh::generate(1.0, 1.0, 1.0)->vertices);
        MeshAssetLoader::compileFile($compiled);
        unlink($compiled);
        file_put_contents($this->dir . '/broken.mesh.bin', 'garbage');

        $errors = [];
        $count = MeshAssetLoader::registerAll($this->dir, static function (string $id, string $error) use (&$errors): void {
            $errors[$id] = $error;
        });

        $this->assertSame(3, $count);
        $this->assertSame(['bin_only', 'broken', 'json_only'], array_keys(MeshAssetLoader::discover($this->dir)));
        $this->assertSame(24, MeshRegistry::get('json_only')?->vertexCount());
        $this->assertSame(24, MeshRegistry::get('bin_only')?->vertexCount());
        $this->assertSame(0, MeshRegistry::get('broken')?->vertexCount());
        $this->assertArrayHasKey('broken', $errors);
    }

    public function testMissingDirectoryRegistersNothing(): void
    {
        $this->assertSame(0, MeshAssetLoader::registerAll($this->dir . '/nope'));
    }

    /**
     * @param float[] $vertices
     * @param ?int[]  $indices
     */
    private function writeAsset(string $name, array $vertices, ?array $indices = null): string
    {
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $count = intdiv(count($vertices), 3);
        $path = $this->dir . '/' . $name . MeshAssetLoader::SOURCE_SUFFIX;
        file_put_contents($path, (string) json_encode(['name' => basename($name), 'raw' => [
            'vertices' => $vertices,
            'normals' => array_fill(0, $count * 3, 0.5),
            'uvs' => array_fill(0, $count * 2, 0.25),
            'indices' => $indices ?? $box->indices,
        ]], JSON_PRETTY_PRINT));
        return $path;
    }
}
