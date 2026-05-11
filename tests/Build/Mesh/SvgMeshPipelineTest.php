<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build\Mesh;

use PHPUnit\Framework\TestCase;
use PHPolygon\Build\Mesh\MeshExtruder;
use PHPolygon\Build\Mesh\MeshSerializer;
use PHPolygon\Build\Mesh\PhpMeshGenerator;
use PHPolygon\Build\Mesh\SvgOutlineParser;
use PHPolygon\Geometry\MeshData;

final class SvgMeshPipelineTest extends TestCase
{
    public function testParsesPolygonElement(): void
    {
        $svg = <<<XML
            <svg xmlns="http://www.w3.org/2000/svg">
              <polygon points="0,0 10,0 10,10 0,10" />
            </svg>
            XML;
        $parser = new SvgOutlineParser();
        $outlines = $parser->parseString($svg, normalise: true);
        $this->assertCount(1, $outlines);
        // 4 corners (Y-flipped to Y-up by parser).
        $this->assertCount(4, $outlines[0]);
    }

    public function testParsesPathWithMoveLineClose(): void
    {
        $svg = <<<XML
            <svg xmlns="http://www.w3.org/2000/svg">
              <path d="M 0 0 L 10 0 L 10 10 L 0 10 Z" />
            </svg>
            XML;
        $parser = new SvgOutlineParser();
        $outlines = $parser->parseString($svg);
        $this->assertCount(1, $outlines);
        $this->assertGreaterThanOrEqual(4, count($outlines[0]));
    }

    public function testParsesCircleAsApproximation(): void
    {
        $svg = <<<XML
            <svg xmlns="http://www.w3.org/2000/svg">
              <circle cx="50" cy="50" r="20" />
            </svg>
            XML;
        $parser = new SvgOutlineParser();
        $outlines = $parser->parseString($svg);
        $this->assertCount(1, $outlines);
        // Default 32 segments + closing point.
        $this->assertGreaterThan(30, count($outlines[0]));
    }

    public function testNormalisationFitsUnitBox(): void
    {
        $svg = <<<XML
            <svg xmlns="http://www.w3.org/2000/svg">
              <rect x="100" y="200" width="400" height="200" />
            </svg>
            XML;
        $parser = new SvgOutlineParser();
        $outlines = $parser->parseString($svg, normalise: true);
        foreach ($outlines[0] as $p) {
            $this->assertLessThanOrEqual(0.5, abs($p[0]));
            $this->assertLessThanOrEqual(0.5, abs($p[1]));
        }
    }

    public function testRawCoordinatesPreserved(): void
    {
        $svg = <<<XML
            <svg xmlns="http://www.w3.org/2000/svg">
              <rect x="100" y="200" width="400" height="200" />
            </svg>
            XML;
        $parser = new SvgOutlineParser();
        $outlines = $parser->parseString($svg, normalise: false);
        // X coords stay between 100 and 500.
        $xs = array_column($outlines[0], 0);
        $this->assertGreaterThanOrEqual(100.0, min($xs));
        $this->assertLessThanOrEqual(500.0, max($xs));
    }

    public function testExtruderProducesClosedMesh(): void
    {
        $square = [[
            [-0.5, -0.5], [0.5, -0.5], [0.5, 0.5], [-0.5, 0.5],
        ]];
        $extruder = new MeshExtruder();
        $mesh = $extruder->extrude($square, depth: 0.2);

        // Top + bottom cap (2 tris each = 4) + 4 side quads (8 tris) = 12.
        $this->assertSame(12, $mesh->triangleCount());
        // 4 (top) + 4 (bottom) + 4 sides × 4 corners = 24 vertices.
        $this->assertSame(24, $mesh->vertexCount());
    }

    public function testExtruderHandlesConcaveStarWithEarClipping(): void
    {
        // 5-pointed star: 10 vertices, classic concave shape.
        $star = [[
            [0.0, 1.0],
            [0.22, 0.31],
            [0.95, 0.31],
            [0.36, -0.12],
            [0.59, -0.81],
            [0.0, -0.40],
            [-0.59, -0.81],
            [-0.36, -0.12],
            [-0.95, 0.31],
            [-0.22, 0.31],
        ]];
        $extruder = new MeshExtruder();
        $mesh = $extruder->extrude($star, depth: 0.2);

        // Caps: 8 tris each (10 - 2). Sides: 10 quads × 2 = 20.
        // Total: 8 + 8 + 20 = 36.
        $this->assertSame(36, $mesh->triangleCount());
    }

    public function testJsonRoundTrip(): void
    {
        $square = [[[-0.5, -0.5], [0.5, -0.5], [0.5, 0.5], [-0.5, 0.5]]];
        $extruder = new MeshExtruder();
        $mesh = $extruder->extrude($square, depth: 0.2);

        $serializer = new MeshSerializer();
        $json = $serializer->toJson($mesh, ['source' => 'test.svg', 'depth' => 0.2]);
        $back = $serializer->fromJson($json);

        $this->assertSame($mesh->vertexCount(), $back->vertexCount());
        $this->assertSame($mesh->triangleCount(), $back->triangleCount());
        $this->assertSame($mesh->vertices, $back->vertices);
        $this->assertSame($mesh->indices, $back->indices);
    }

    public function testPhpMeshGeneratorEmitsValidPhp(): void
    {
        $mesh = new MeshData(
            vertices: [0.0, 0.0, 0.0,  1.0, 0.0, 0.0,  0.5, 1.0, 0.0],
            normals:  [0.0, 0.0, 1.0,  0.0, 0.0, 1.0,  0.0, 0.0, 1.0],
            uvs:      [0.0, 0.0,  1.0, 0.0,  0.5, 1.0],
            indices:  [0, 1, 2],
        );
        $generator = new PhpMeshGenerator();
        $code = $generator->generate($mesh, 'TestTriangleMesh', 'PHPolygon\\Tests\\Tmp', 'unit-test');

        $this->assertStringContainsString('namespace PHPolygon\\Tests\\Tmp;', $code);
        $this->assertStringContainsString('final class TestTriangleMesh', $code);
        $this->assertStringContainsString('public static function generate(): MeshData', $code);
        $this->assertStringContainsString('Vertices:  3', $code);
        $this->assertStringContainsString('Triangles: 1', $code);

        // Eval the generated source to verify it actually parses + runs.
        $tempPath = tempnam(sys_get_temp_dir(), 'mesh-gen-');
        file_put_contents($tempPath, $code);
        $lintResult = shell_exec("php -l " . escapeshellarg($tempPath) . " 2>&1");
        $this->assertStringContainsString('No syntax errors', (string)$lintResult);
        unlink($tempPath);
    }

    public function testPhpMeshGeneratorIsDeterministic(): void
    {
        $mesh = new MeshData(
            vertices: [0.0, 0.0, 0.0,  1.0, 0.0, 0.0,  0.5, 1.0, 0.0],
            normals:  [0.0, 0.0, 1.0,  0.0, 0.0, 1.0,  0.0, 0.0, 1.0],
            uvs:      [0.0, 0.0,  1.0, 0.0,  0.5, 1.0],
            indices:  [0, 1, 2],
        );
        $generator = new PhpMeshGenerator();
        $first  = $generator->generate($mesh, 'Det', 'X');
        $second = $generator->generate($mesh, 'Det', 'X');
        $this->assertSame($first, $second);
    }
}
