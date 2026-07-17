<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Component\ProceduralMesh;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\ProceduralMeshEvaluator;
use RuntimeException;

use PHPUnit\Framework\TestCase;

class ProceduralMeshEvaluatorTest extends TestCase
{
    public function testEvaluatesASinglePrimitive(): void
    {
        $graph = new ProceduralMesh();
        $graph->nodes = [
            ['id' => 'b', 'type' => 'box', 'params' => ['width' => 2.0, 'height' => 1.0, 'depth' => 1.0]],
        ];
        $graph->output = 'b';

        $mesh = (new ProceduralMeshEvaluator())->evaluate($graph);

        $this->assertGreaterThan(0, $mesh->vertexCount());
        $this->assertSame(12, $mesh->triangleCount(), 'a box is 12 triangles');
    }

    public function testTransformNodeMovesGeometry(): void
    {
        $graph = new ProceduralMesh();
        $graph->nodes = [
            ['id' => 'b', 'type' => 'box', 'params' => ['width' => 1.0, 'height' => 1.0, 'depth' => 1.0]],
            ['id' => 't', 'type' => 'transform', 'inputs' => ['mesh' => 'b'], 'params' => ['ty' => 10.0]],
        ];
        $graph->output = 't';

        $evaluator = new ProceduralMeshEvaluator();
        $base = $evaluator->evaluate((function () {
            $g = new ProceduralMesh();
            $g->nodes = [['id' => 'b', 'type' => 'box', 'params' => ['width' => 1.0, 'height' => 1.0, 'depth' => 1.0]]];
            $g->output = 'b';
            return $g;
        })());
        $moved = $evaluator->evaluate($graph);

        // Every Y coordinate shifted up by 10.
        for ($i = 1, $n = count($base->vertices); $i < $n; $i += 3) {
            $this->assertEqualsWithDelta($base->vertices[$i] + 10.0, $moved->vertices[$i], 1e-5);
        }
    }

    public function testCombineMergesInputsWithOffsetIndices(): void
    {
        $graph = new ProceduralMesh();
        $graph->nodes = [
            ['id' => 'a', 'type' => 'box', 'params' => ['width' => 1.0, 'height' => 1.0, 'depth' => 1.0]],
            ['id' => 'b', 'type' => 'box', 'params' => ['width' => 1.0, 'height' => 1.0, 'depth' => 1.0]],
            ['id' => 'c', 'type' => 'combine', 'inputs' => ['a' => 'a', 'b' => 'b']],
        ];
        $graph->output = 'c';

        $mesh = (new ProceduralMeshEvaluator())->evaluate($graph);

        $this->assertSame(48, $mesh->vertexCount(), 'two boxes = 48 vertices');
        $this->assertSame(24, $mesh->triangleCount());
        $this->assertSame(max($mesh->indices), 47, 'merge must offset the second box indices');
    }

    public function testNewGeneratorsProduceGeometry(): void
    {
        foreach (['torus', 'octahedron', 'wedge'] as $type) {
            $graph = new ProceduralMesh();
            $graph->nodes = [['id' => 'g', 'type' => $type]];
            $graph->output = 'g';

            $mesh = (new ProceduralMeshEvaluator())->evaluate($graph);

            $this->assertGreaterThan(0, $mesh->vertexCount(), "{$type} produces vertices");
            $this->assertGreaterThan(0, $mesh->triangleCount(), "{$type} produces triangles");
        }
    }

    public function testMirrorDoublesGeometryAndReflectsAcrossAxis(): void
    {
        // Box pushed to +X, then mirrored across X → a symmetric pair whose
        // combined X coordinates cancel to ~0.
        $graph = new ProceduralMesh();
        $graph->nodes = [
            ['id' => 'b', 'type' => 'box', 'params' => ['width' => 1.0, 'height' => 1.0, 'depth' => 1.0]],
            ['id' => 't', 'type' => 'transform', 'inputs' => ['mesh' => 'b'], 'params' => ['tx' => 5.0]],
            ['id' => 'm', 'type' => 'mirror', 'inputs' => ['mesh' => 't'], 'params' => ['axis' => 0.0]],
        ];
        $graph->output = 'm';

        $mesh = (new ProceduralMeshEvaluator())->evaluate($graph);

        $this->assertSame(48, $mesh->vertexCount(), 'mirror merges original + reflected copy (2 × 24)');

        $sumX = 0.0;
        for ($i = 0, $n = count($mesh->vertices); $i < $n; $i += 3) {
            $sumX += $mesh->vertices[$i];
        }
        $this->assertEqualsWithDelta(0.0, $sumX, 1e-4, 'mirror across X makes the mesh X-symmetric');
    }

    public function testUnknownNodeTypeThrows(): void
    {
        $graph = new ProceduralMesh();
        $graph->nodes = [['id' => 'x', 'type' => 'wobble']];
        $graph->output = 'x';

        $this->expectException(RuntimeException::class);
        (new ProceduralMeshEvaluator())->evaluate($graph);
    }

    public function testCycleThrows(): void
    {
        $graph = new ProceduralMesh();
        $graph->nodes = [
            ['id' => 'a', 'type' => 'transform', 'inputs' => ['mesh' => 'b']],
            ['id' => 'b', 'type' => 'transform', 'inputs' => ['mesh' => 'a']],
        ];
        $graph->output = 'a';

        $this->expectException(RuntimeException::class);
        (new ProceduralMeshEvaluator())->evaluate($graph);
    }

    public function testPublishRegistersAndBumpsVersion(): void
    {
        $graph = new ProceduralMesh();
        $graph->nodes = [['id' => 'b', 'type' => 'box']];
        $graph->output = 'b';
        $graph->meshId = 'test_proc_mesh_' . __FUNCTION__;

        $before = MeshRegistry::version($graph->meshId);
        (new ProceduralMeshEvaluator())->publish($graph);

        $this->assertTrue(MeshRegistry::has($graph->meshId));
        $this->assertSame($before + 1, MeshRegistry::version($graph->meshId));
    }

    public function testGraphSerializesThroughTheComponentPipeline(): void
    {
        $graph = new ProceduralMesh();
        $graph->nodes = [
            ['id' => 'b', 'type' => 'box', 'params' => ['width' => 2.0]],
            ['id' => 't', 'type' => 'transform', 'inputs' => ['mesh' => 'b'], 'params' => ['ty' => 3.0]],
        ];
        $graph->output = 't';
        $graph->meshId = 'lantern_mesh';

        $serializer = new AttributeSerializer();
        $restored = $serializer->fromArray($serializer->toArray($graph), ProceduralMesh::class);

        $this->assertInstanceOf(ProceduralMesh::class, $restored);
        $this->assertSame('t', $restored->output);
        $this->assertSame('lantern_mesh', $restored->meshId);
        $this->assertSame($graph->nodes, $restored->nodes);

        // The restored graph still evaluates.
        $this->assertGreaterThan(0, (new ProceduralMeshEvaluator())->evaluate($restored)->vertexCount());
    }
}
