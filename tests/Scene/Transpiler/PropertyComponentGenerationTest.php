<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene\Transpiler;

use PHPolygon\Component\ProceduralMesh;
use PHPolygon\Component\Terrain;
use PHPolygon\Scene\Transpiler\PhpCodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Components that declare their state as public `#[Property]` fields instead of
 * constructor parameters — ProceduralMesh, RawMesh, Terrain, TerrainScatter,
 * InstancedTerrain — used to lose every value on the way into a generated PHP
 * scene: the generator only rendered constructor arguments, so an edited mesh
 * graph or a sculpted heightmap silently came back empty.
 */
class PropertyComponentGenerationTest extends TestCase
{
    /** @param array<string, mixed> $component */
    private function generateWith(array $component): string
    {
        return (new PhpCodeGenerator())->generate([
            'name' => 'TestScene',
            'entities' => [
                [
                    'name' => 'Thing',
                    'components' => [$component],
                    'children' => [],
                ],
            ],
        ]);
    }

    public function testProceduralMeshGraphSurvivesGeneration(): void
    {
        $code = $this->generateWith([
            '_class' => ProceduralMesh::class,
            'nodes' => [['id' => 'box', 'type' => 'box', 'params' => ['width' => 2.0]]],
            'output' => 'box',
            'meshId' => 'crate',
        ]);

        $this->assertStringContainsString("'id' => 'box'", $code);
        $this->assertStringContainsString("'type' => 'box'", $code);
        $this->assertStringContainsString("output = 'box'", $code);
        $this->assertStringContainsString("meshId = 'crate'", $code);
        // The bare, value-less form is exactly what used to be emitted.
        $this->assertStringNotContainsString('->with(new ProceduralMesh())', $code);
    }

    public function testTerrainHeightmapSurvivesGeneration(): void
    {
        $code = $this->generateWith([
            '_class' => Terrain::class,
            'gridWidth' => 65,
            'gridDepth' => 65,
            'sizeX' => 128,
            'sizeZ' => 128,
            'heights' => 'BASE64HEIGHTS',
            'materialId' => 'grass',
        ]);

        $this->assertStringContainsString("heights = 'BASE64HEIGHTS'", $code);
        $this->assertStringContainsString('gridWidth = 65', $code);
        $this->assertStringContainsString("materialId = 'grass'", $code);
    }

    /**
     * Generated scenes declare strict_types, so a float-typed property fed a
     * whole number from JSON has to be emitted as a float literal.
     */
    public function testWholeNumbersForFloatPropertiesKeepTheirDecimal(): void
    {
        $code = $this->generateWith([
            '_class' => Terrain::class,
            'sizeX' => 128,
            'heights' => 'x',
        ]);

        $this->assertStringContainsString('sizeX = 128.0', $code);
    }

    public function testValuesEqualToTheDefaultAreLeftOut(): void
    {
        $code = $this->generateWith([
            '_class' => ProceduralMesh::class,
            'nodes' => [],
            'output' => '',
            'meshId' => '',
        ]);

        $this->assertStringContainsString('->with(new ProceduralMesh())', $code);
        $this->assertStringNotContainsString('static function', $code);
    }

    public function testGeneratedSceneIsValidPhp(): void
    {
        $code = $this->generateWith([
            '_class' => ProceduralMesh::class,
            'nodes' => [['id' => 'box', 'type' => 'box']],
            'output' => 'box',
        ]);

        $file = tempnam(sys_get_temp_dir(), 'scene') . '.php';
        file_put_contents($file, $code);
        $lint = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $lint, $status);
        unlink($file);

        $this->assertSame(0, $status, "Generated scene is not valid PHP:\n" . implode("\n", $lint) . "\n\n" . $code);
    }
}
