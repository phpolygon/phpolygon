<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\InstanceParams;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

/** Parameters per material and per instance, no GPU involved. */
final class InstanceParamsTest extends TestCase
{
    protected function setUp(): void
    {
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    protected function tearDown(): void
    {
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    public function testTheParamsRideInTheUnusedRowOfTheMatrix(): void
    {
        $matrix = Mat4::translation(1.0, 2.0, 3.0);
        $values = InstanceParams::matrix($matrix, 0.25, 0.5, 0.75)->toArray();

        self::assertSame([0.25, 0.5, 0.75], [$values[3], $values[7], $values[11]]);
        self::assertSame(1.0, $values[15], 'the fourth one stays: it is what makes the matrix affine');
        self::assertSame([1.0, 2.0, 3.0], [$values[12], $values[13], $values[14]]);

        $flat = array_merge($matrix->toArray(), $matrix->toArray());
        InstanceParams::write($flat, 1, 0.25, 0.5, 0.75);
        self::assertSame([0.25, 0.5, 0.75], [$flat[19], $flat[23], $flat[27]]);
        self::assertSame(0.0, $flat[3], 'the other instance keeps its own');
    }

    public function testACopyOfAMaterialKeepsItsParams(): void
    {
        $material = (new Material(params: ['u_fx_wear' => 0.5]))->withParams(['u_fx_tint' => [1.0, 0.0, 0.0]]);
        self::assertSame(['u_fx_wear' => 0.5, 'u_fx_tint' => [1.0, 0.0, 0.0]], $material->params);
        self::assertSame($material->params, $material->withEmission(new Color(1.0, 1.0, 1.0))->params);
    }

    public function testAMaterialParameterIsAnOrdinaryDeclaredUniform(): void
    {
        ProcModeShaderRegistry::declareMaterialParameter('u_fx_wear', 'float', 0.25);
        self::assertSame(['u_fx_wear' => 0.25], ProcModeShaderRegistry::materialParameters());
        self::assertStringContainsString('uniform float u_fx_wear;', ProcModeShaderRegistry::uniformDeclarations());
    }

    public function testInstanceParamsDefineReachesEveryProgram(): void
    {
        self::assertNotContains('PHPOLYGON_INSTANCE_PARAMS', ProcModeShaderRegistry::variantDefines());
        ProcModeShaderRegistry::defineVariant('a', [2]);
        ProcModeShaderRegistry::enableInstanceParams();
        self::assertContains('PHPOLYGON_INSTANCE_PARAMS', ProcModeShaderRegistry::variantDefines());
        self::assertContains('PHPOLYGON_INSTANCE_PARAMS', ProcModeShaderRegistry::variantDefines('a'));
    }

    public function testEveryVertexStageThatReadsInstanceMatricesClearsTheRow(): void
    {
        $dir = dirname(__DIR__, 2) . '/resources/shaders/source/vio/';
        foreach ((array) glob($dir . '*.vert.glsl') as $file) {
            $source = (string) file_get_contents((string) $file);
            if (str_contains($source, 'a_instance_col0, a_instance_col1')) {
                self::assertStringContainsString('#ifdef PHPOLYGON_INSTANCE_PARAMS', $source, basename((string) $file));
                self::assertStringContainsString('model[2].w = 0.0;', $source, basename((string) $file));
            }
        }
    }

    /** @return list<object> */
    private static function commandsFor(World $world): array
    {
        $spy = new class extends NullRenderer3D {
            /** @var list<object> */
            public array $commands = [];

            public function render(RenderCommandList $commandList): void
            {
                $this->commands = $commandList->getCommands();
            }
        };
        $list = new RenderCommandList();
        $system = new Renderer3DSystem($spy, $list);
        $list->add(new SetCamera(Mat4::translation(0.0, 0.0, -10.0), Mat4::perspective(1.0, 1.0, 0.1, 100.0)));
        $system->render($world);
        return $spy->commands;
    }

    private static function entity(World $world, Vec3 $at, bool $batchable, ?array $params): void
    {
        $entity = $world->createEntity();
        $transform = new Transform3D(position: $at);
        $transform->worldMatrix = Mat4::translation($at->x, $at->y, $at->z);
        $world->attachComponent($entity->id, $transform);
        $renderer = new MeshRenderer('box', 'plain', castShadows: false, batchable: $batchable);
        $renderer->shaderParams = $params;
        $world->attachComponent($entity->id, $renderer);
    }

    public function testAnEntityHandsItsParamsToItsDraw(): void
    {
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('plain', new Material());
        $world = new World();
        self::entity($world, new Vec3(0.0, 0.0, 0.0), false, [0.1, 0.2, 0.3]);

        $draws = array_values(array_filter(self::commandsFor($world), static fn (object $c): bool => $c instanceof DrawMesh));
        self::assertCount(1, $draws);
        self::assertSame([0.1, 0.2, 0.3], $draws[0]->params);
    }

    public function testABatchPutsTheParamsIntoItsMatrices(): void
    {
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('plain', new Material());
        $world = new World();
        self::entity($world, new Vec3(-1.0, 0.0, 0.0), true, [0.1, 0.2, 0.3]);
        self::entity($world, new Vec3(1.0, 0.0, 0.0), true, null);

        $draws = array_values(array_filter(self::commandsFor($world), static fn (object $c): bool => $c instanceof DrawMeshInstanced));
        self::assertCount(1, $draws);
        $first = $draws[0]->matrices[0]->toArray();
        self::assertSame([0.1, 0.2, 0.3], [$first[3], $first[7], $first[11]]);
        self::assertSame(0.0, $draws[0]->matrices[1]->toArray()[3]);
    }

    public function testABatchOfOneStaysASingleDrawWithItsParams(): void
    {
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('plain', new Material());
        $world = new World();
        self::entity($world, new Vec3(0.0, 0.0, 0.0), true, [0.1, 0.2, 0.3]);

        $draws = array_values(array_filter(self::commandsFor($world), static fn (object $c): bool => $c instanceof DrawMesh));
        self::assertCount(1, $draws);
        self::assertSame([0.1, 0.2, 0.3], $draws[0]->params);
        self::assertSame(0.0, $draws[0]->modelMatrix->toArray()[3], 'no params in the matrix of a single draw');
    }
}
