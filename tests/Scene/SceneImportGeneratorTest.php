<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\Transpiler\SceneImportGenerator;

class SceneImportGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        // build() registers into the global registries; keep tests isolated.
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testGeneratesRunnableSceneThatRegistersAssets(): void
    {
        $namespace = 'Proto\\Import\\S' . bin2hex(random_bytes(5));
        $import = [
            'name' => 'imported_demo',
            '_scene' => $namespace . '\\Ignored',
            'systems' => [],
            'meshes' => [
                'box_6x12x5' => ['generator' => 'BoxMesh', 'args' => [6, 12, 5]],
                'sphere_r1' => ['generator' => 'SphereMesh', 'args' => [1, 12, 16]],
            ],
            'materials' => [
                'mat_wall' => ['albedo' => '#9a5b3a', 'roughness' => 0.8, 'metallic' => 0.0],
            ],
            'entities' => [
                [
                    'name' => 'Building',
                    'components' => [
                        [
                            '_class' => Transform3D::class,
                            'position' => ['x' => 10, 'y' => 0, 'z' => 5],
                            'rotation' => ['x' => 0, 'y' => 0.4, 'z' => 0, 'w' => 0.92],
                            'scale' => ['x' => 1, 'y' => 1, 'z' => 1],
                        ],
                        [
                            '_class' => MeshRenderer::class,
                            'meshId' => 'box_6x12x5',
                            'materialId' => 'mat_wall',
                        ],
                    ],
                ],
            ],
        ];

        $php = (new SceneImportGenerator())->generate($import);

        // Mesh args render type-correctly: box dims as floats, sphere segments as ints.
        $this->assertStringContainsString(
            "MeshRegistry::register('box_6x12x5', BoxMesh::generate(6.0, 12.0, 5.0));",
            $php,
        );
        $this->assertStringContainsString(
            "MeshRegistry::register('sphere_r1', SphereMesh::generate(1.0, 12, 16));",
            $php,
        );
        $this->assertStringContainsString(
            "MaterialRegistry::register('mat_wall', new Material(albedo: Color::hex('#9a5b3a'), roughness: 0.8, metallic: 0.0));",
            $php,
        );
        $this->assertStringContainsString('use PHPolygon\\Geometry\\BoxMesh;', $php);
        $this->assertStringContainsString('use PHPolygon\\Rendering\\MaterialRegistry;', $php);

        // It must load, instantiate, and register its assets when built.
        $tmp = tempnam(sys_get_temp_dir(), 'phpolygon-import-') . '.php';
        file_put_contents($tmp, $php);
        try {
            require $tmp;
            /** @var class-string<Scene> $fqcn */
            $fqcn = $namespace . '\\ImportedDemo';
            $scene = new $fqcn();
            $this->assertInstanceOf(Scene::class, $scene);

            $builder = new SceneBuilder();
            $scene->build($builder);

            $this->assertTrue(MeshRegistry::has('box_6x12x5'), 'mesh registered by build()');
            $this->assertTrue(MeshRegistry::has('sphere_r1'));
            $this->assertTrue(MaterialRegistry::has('mat_wall'), 'material registered by build()');

            $material = MaterialRegistry::get('mat_wall');
            $this->assertNotNull($material);
            $this->assertEqualsWithDelta(0.8, $material->roughness, 1e-6);

            $declarations = $builder->getDeclarations();
            $this->assertCount(1, $declarations);
            $this->assertSame('Building', $declarations[0]->getName());
        } finally {
            unlink($tmp);
        }
    }

    public function testIgnoresUnknownGeneratorsAndKeepsEmptyMaterialsValid(): void
    {
        $php = (new SceneImportGenerator())->generate([
            'name' => 'edge',
            'meshes' => ['weird' => ['generator' => 'TeapotMesh', 'args' => [1]]],
            'materials' => ['plain' => []],
            'entities' => [],
        ]);

        // Unknown generator is skipped (no registration line for it).
        $this->assertStringNotContainsString('TeapotMesh', $php);
        // Empty material still produces a valid constructor call.
        $this->assertStringContainsString("MaterialRegistry::register('plain', new Material());", $php);
    }
}
