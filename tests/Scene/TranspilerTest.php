<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera2DComponent;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\ProjectionType;
use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\SceneConfig;
use PHPolygon\Scene\Transpiler\JsonSceneFormat;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPolygon\System\Camera2DSystem;
use PHPolygon\System\Renderer2DSystem;

class SampleScene extends Scene
{
    public function getName(): string
    {
        return 'sample_scene';
    }

    public function getConfig(): SceneConfig
    {
        return new SceneConfig(
            clearColor: Color::hex('#2a2a4a'),
        );
    }

    public function getSystems(): array
    {
        return [Camera2DSystem::class, Renderer2DSystem::class];
    }

    public function build(SceneBuilder $builder): void
    {
        $builder->entity('Camera')
            ->with(new Transform2D())
            ->with(new Camera2DComponent());

        $builder->entity('Player')
            ->with(new Transform2D(position: new Vec2(100, 200)))
            ->with(new SpriteRenderer(textureId: 'player_idle'))
            ->child('Weapon')
                ->with(new Transform2D(position: new Vec2(20, 0)))
                ->with(new SpriteRenderer(textureId: 'sword'));
    }
}

class TranspilerTest extends TestCase
{
    private SceneTranspiler $transpiler;

    protected function setUp(): void
    {
        $this->transpiler = new SceneTranspiler();
    }

    public function testToArrayProducesValidStructure(): void
    {
        $scene = new SampleScene();
        $data = $this->transpiler->toArray($scene);

        $this->assertSame(JsonSceneFormat::VERSION, $data['_version']);
        $this->assertSame(SampleScene::class, $data['_scene']);
        $this->assertSame('sample_scene', $data['name']);
        $this->assertIsArray($data['config']);
        $this->assertIsArray($data['systems']);
        $this->assertIsArray($data['entities']);
    }

    public function testToArrayEntities(): void
    {
        $scene = new SampleScene();
        $data = $this->transpiler->toArray($scene);

        $this->assertCount(2, $data['entities']);

        // Camera entity
        $camera = $data['entities'][0];
        $this->assertSame('Camera', $camera['name']);
        $this->assertCount(2, $camera['components']);
        $this->assertSame(Transform2D::class, $camera['components'][0]['_class']);
        $this->assertSame(Camera2DComponent::class, $camera['components'][1]['_class']);

        // Player entity with child
        $player = $data['entities'][1];
        $this->assertSame('Player', $player['name']);
        $this->assertArrayHasKey('children', $player);
        $this->assertCount(1, $player['children']);

        $weapon = $player['children'][0];
        $this->assertSame('Weapon', $weapon['name']);
    }

    public function testToJsonReturnsValidJson(): void
    {
        $scene = new SampleScene();
        $json = $this->transpiler->toJson($scene);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('sample_scene', $decoded['name']);
    }

    public function testJsonFormatValidation(): void
    {
        $valid = [
            'name' => 'test',
            'entities' => [
                ['name' => 'A', 'components' => [['_class' => 'Foo']]],
            ],
        ];
        JsonSceneFormat::validate($valid);
        $this->assertTrue(true); // No exception

        $this->expectException(\RuntimeException::class);
        JsonSceneFormat::validate(['entities' => []]); // Missing name
    }

    public function testFromArrayGeneratesPhpCode(): void
    {
        $scene = new SampleScene();
        $data = $this->transpiler->toArray($scene);
        $php = $this->transpiler->fromArray($data);

        $this->assertStringContainsString('class SampleScene extends Scene', $php);
        $this->assertStringContainsString("return 'sample_scene'", $php);
        $this->assertStringContainsString('->entity(\'Camera\')', $php);
        $this->assertStringContainsString('->entity(\'Player\')', $php);
        $this->assertStringContainsString('->child(\'Weapon\')', $php);
        $this->assertStringContainsString('new Transform2D(', $php);
        $this->assertStringContainsString('new SpriteRenderer(', $php);
    }

    public function testClassNameAndNamespaceComeFromSceneFqcn(): void
    {
        $php = $this->transpiler->fromArray([
            '_version' => 1,
            '_scene' => 'Acme\\Game\\Scenes\\LevelOneScene',
            'name' => 'level_one',
            'systems' => [],
            'entities' => [],
        ]);

        // The original class/namespace is preserved so re-saving an edited
        // scene updates the same class/file (not a name-derived duplicate).
        $this->assertStringContainsString('namespace Acme\\Game\\Scenes;', $php);
        $this->assertStringContainsString('class LevelOneScene extends Scene', $php);
        $this->assertStringContainsString("return 'level_one'", $php);
    }

    public function testClassNameFallsBackToSceneNameWithoutFqcn(): void
    {
        $php = $this->transpiler->fromArray([
            '_version' => 1,
            'name' => 'my_level',
            'systems' => [],
            'entities' => [],
        ]);

        $this->assertStringContainsString('class MyLevel extends Scene', $php);
    }

    public function testFromArrayIncludesUseStatements(): void
    {
        $scene = new SampleScene();
        $data = $this->transpiler->toArray($scene);
        $php = $this->transpiler->fromArray($data);

        $this->assertStringContainsString('use PHPolygon\\Scene\\Scene;', $php);
        $this->assertStringContainsString('use PHPolygon\\Scene\\SceneBuilder;', $php);
        $this->assertStringContainsString('use PHPolygon\\Component\\Transform2D;', $php);
    }

    public function testFromArrayIncludesSystems(): void
    {
        $scene = new SampleScene();
        $data = $this->transpiler->toArray($scene);
        $php = $this->transpiler->fromArray($data);

        $this->assertStringContainsString('getSystems(): array', $php);
        $this->assertStringContainsString('Camera2DSystem::class', $php);
        $this->assertStringContainsString('Renderer2DSystem::class', $php);
    }

    public function testGeneratedPhpReconstructsValueObjectsAndRuns(): void
    {
        // Regression: PhpCodeGenerator emitted Quaternion (Transform3D.rotation)
        // and Color (SceneConfig.clearColor) as raw arrays, producing PHP that
        // type-errors at runtime (array given where ?Quaternion / ?Color
        // expected). php -l does not catch this - only running it does.
        $namespace = 'Proto\\Gen\\S' . bin2hex(random_bytes(5));
        $data = [
            '_version' => 1,
            '_scene' => $namespace . '\\GenScene',
            'name' => 'gen_scene',
            'config' => [
                '_class' => SceneConfig::class,
                'clearColor' => ['r' => 0.1, 'g' => 0.2, 'b' => 0.3, 'a' => 1.0],
                'gravity' => ['x' => 0.0, 'y' => 9.8],
                'timeScale' => 1.0,
            ],
            'systems' => [],
            'entities' => [
                [
                    'name' => 'Box',
                    'components' => [
                        [
                            '_class' => Transform3D::class,
                            'position' => ['x' => 1.0, 'y' => 2.0, 'z' => 3.0],
                            'rotation' => ['x' => 0.1, 'y' => 0.2, 'z' => 0.3, 'w' => 0.9],
                            'scale' => ['x' => 1.0, 'y' => 1.0, 'z' => 1.0],
                            'parentEntityId' => null,
                            'childEntityIds' => [],
                        ],
                        [
                            '_class' => MeshRenderer::class,
                            'meshId' => 'box',
                            'materialId' => 'm',
                            'castShadows' => true,
                        ],
                    ],
                ],
            ],
        ];

        $php = $this->transpiler->fromArray($data);

        // Value objects reconstructed with the right constructor + imports.
        $this->assertStringContainsString('new Quaternion(', $php);
        $this->assertStringContainsString('new Color(', $php);
        $this->assertStringContainsString('use PHPolygon\\Math\\Quaternion;', $php);
        $this->assertStringContainsString('use PHPolygon\\Rendering\\Color;', $php);
        $this->assertStringNotContainsString("rotation: ['x'", $php);
        $this->assertStringNotContainsString("clearColor: ['r'", $php);

        // The generated PHP must actually load and run.
        $tmp = tempnam(sys_get_temp_dir(), 'phpolygon-scene-') . '.php';
        file_put_contents($tmp, $php);
        try {
            require $tmp;
            /** @var class-string<Scene> $fqcn */
            $fqcn = $namespace . '\\GenScene';
            $scene = new $fqcn();
            $this->assertInstanceOf(Scene::class, $scene);

            $config = $scene->getConfig();
            $this->assertEqualsWithDelta(0.1, $config->clearColor->r, 1e-6);
            $this->assertEqualsWithDelta(9.8, $config->gravity->y, 1e-6);

            $builder = new SceneBuilder();
            $scene->build($builder);
            $declarations = $builder->getDeclarations();
            $this->assertCount(1, $declarations);

            $transform = null;
            foreach ($declarations[0]->getComponents() as $component) {
                if ($component instanceof Transform3D) {
                    $transform = $component;
                }
            }
            $this->assertInstanceOf(Transform3D::class, $transform);
            $this->assertTrue(
                $transform->rotation->equals(new Quaternion(0.1, 0.2, 0.3, 0.9)),
                'Quaternion rotation must survive JSON -> PHP -> runtime',
            );
            $this->assertTrue($transform->position->equals(new Vec3(1.0, 2.0, 3.0)));
        } finally {
            unlink($tmp);
        }
    }

    public function testGeneratedPhpRendersEnumConstructorArgs(): void
    {
        // Regression: enum-typed params (Camera3DComponent.projectionType, a
        // pure enum) were rendered as bare scalars, type-erroring at load.
        $namespace = 'Proto\\Gen\\E' . bin2hex(random_bytes(5));
        $data = [
            '_version' => 1,
            '_scene' => $namespace . '\\EnumScene',
            'name' => 'enum_scene',
            'systems' => [],
            'entities' => [
                [
                    'name' => 'Cam',
                    'components' => [
                        [
                            '_class' => Camera3DComponent::class,
                            'fov' => 60.0,
                            'near' => 0.1,
                            'far' => 1000.0,
                            'projectionType' => 'Orthographic',
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ];

        $php = $this->transpiler->fromArray($data);

        $this->assertStringContainsString('ProjectionType::Orthographic', $php);
        $this->assertStringContainsString('use PHPolygon\\Component\\ProjectionType;', $php);
        $this->assertStringNotContainsString("projectionType: 'Orthographic'", $php);

        $tmp = tempnam(sys_get_temp_dir(), 'phpolygon-enum-') . '.php';
        file_put_contents($tmp, $php);
        try {
            require $tmp;
            /** @var class-string<Scene> $fqcn */
            $fqcn = $namespace . '\\EnumScene';
            $scene = new $fqcn();

            $builder = new SceneBuilder();
            $scene->build($builder);

            $camera = null;
            foreach ($builder->getDeclarations()[0]->getComponents() as $component) {
                if ($component instanceof Camera3DComponent) {
                    $camera = $component;
                }
            }
            $this->assertInstanceOf(Camera3DComponent::class, $camera);
            $this->assertSame(ProjectionType::Orthographic, $camera->projectionType);
        } finally {
            unlink($tmp);
        }
    }

    public function testGeneratedPhpKeepsSiblingChildrenUnderTheSameParent(): void
    {
        // Regression: child() and with() both return the child, so chaining a
        // second ->child() without ->end() nested the second sibling under the
        // first. Only surfaces with >1 child (the Player/Weapon sample had one).
        $namespace = 'Proto\\Gen\\H' . bin2hex(random_bytes(5));
        $data = [
            '_version' => 1,
            '_scene' => $namespace . '\\HierScene',
            'name' => 'hier_scene',
            'systems' => [],
            'entities' => [
                [
                    'name' => 'Parent',
                    'components' => [['_class' => Transform2D::class]],
                    'children' => [
                        [
                            'name' => 'ChildA',
                            'components' => [['_class' => Transform2D::class]],
                            'children' => [['name' => 'GrandA', 'components' => [['_class' => Transform2D::class]]]],
                        ],
                        ['name' => 'ChildB', 'components' => [['_class' => Transform2D::class]]],
                    ],
                ],
            ],
        ];

        $php = $this->transpiler->fromArray($data);

        $tmp = tempnam(sys_get_temp_dir(), 'phpolygon-hier-') . '.php';
        file_put_contents($tmp, $php);
        try {
            require $tmp;
            /** @var class-string<Scene> $fqcn */
            $fqcn = $namespace . '\\HierScene';
            $scene = new $fqcn();

            $builder = new SceneBuilder();
            $scene->build($builder);

            $declarations = $builder->getDeclarations();
            $this->assertCount(1, $declarations);

            $parent = $declarations[0];
            $this->assertSame('Parent', $parent->getName());

            $childNames = array_map(static fn($c) => $c->getName(), $parent->getChildren());
            $this->assertEqualsCanonicalizing(['ChildA', 'ChildB'], $childNames, 'both children must hang under Parent');

            foreach ($parent->getChildren() as $child) {
                if ($child->getName() === 'ChildA') {
                    $this->assertCount(1, $child->getChildren(), 'ChildA keeps its grandchild');
                }
                if ($child->getName() === 'ChildB') {
                    $this->assertCount(0, $child->getChildren(), 'ChildB must not absorb a sibling');
                }
            }
        } finally {
            unlink($tmp);
        }
    }

    public function testRoundtripPreservesStructure(): void
    {
        $scene = new SampleScene();
        $data = $this->transpiler->toArray($scene);
        $json = json_encode($data);
        $restored = json_decode($json, true);

        $this->assertSame($data['name'], $restored['name']);
        $this->assertSame(count($data['entities']), count($restored['entities']));
        $this->assertSame(
            $data['entities'][0]['components'][0]['_class'],
            $restored['entities'][0]['components'][0]['_class'],
        );
    }
}
