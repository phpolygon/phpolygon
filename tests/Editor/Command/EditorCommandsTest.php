<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Editor\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Transform2D;
use PHPolygon\Editor\Command\AddComponentCommand;
use PHPolygon\Editor\Command\CreateEntityCommand;
use PHPolygon\Editor\Command\DeleteEntityCommand;
use PHPolygon\Editor\Command\EditorCommandBus;
use PHPolygon\Editor\Command\GetEntityHierarchyCommand;
use PHPolygon\Editor\Command\ListComponentsCommand;
use PHPolygon\Editor\Command\UpdatePropertyCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class EditorCommandsTest extends TestCase
{
    private EditorContext $context;

    protected function setUp(): void
    {
        $manifest = new ProjectManifest(
            name: 'TestProject',
            version: '0.1.0',
            engineVersion: '*',
            scenesPath: 'src/Scene',
            assetsPath: 'assets',
            psr4Roots: ['TestProject\\' => 'src/'],
            entryScene: 'MainMenu',
        );

        $components = new ComponentRegistry();
        $components->register(Transform2D::class);

        $this->context = new EditorContext(
            manifest: $manifest,
            components: $components,
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: '/tmp/test-project',
        );

        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                [
                    'name' => 'Camera',
                    'components' => [
                        ['_class' => Transform2D::class, 'position' => ['x' => 0, 'y' => 0]],
                    ],
                ],
            ],
        ]);
    }

    public function testListComponents(): void
    {
        $cmd = new ListComponentsCommand();
        $result = $cmd->execute($this->context);

        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey(Transform2D::class, $result['components']);
    }

    public function testListComponentsGrouped(): void
    {
        $cmd = new ListComponentsCommand(['grouped' => true]);
        $result = $cmd->execute($this->context);

        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('Core', $result['categories']);
    }

    public function testCreateEntity(): void
    {
        $cmd = new CreateEntityCommand(['name' => 'Player']);
        $result = $cmd->execute($this->context);

        $this->assertSame('Player', $result['created']);
        $this->assertNotNull($this->context->activeDocument->getEntity('Player'));
    }

    public function testDeleteEntity(): void
    {
        $cmd = new DeleteEntityCommand(['name' => 'Camera']);
        $cmd->execute($this->context);

        $this->assertNull($this->context->activeDocument->getEntity('Camera'));
    }

    public function testAddComponent(): void
    {
        $cmd = new AddComponentCommand([
            'entity' => 'Camera',
            'component' => Transform2D::class,
        ]);
        $cmd->execute($this->context);

        $camera = $this->context->activeDocument->getEntity('Camera');
        $this->assertCount(2, $camera['components']);
    }

    public function testUpdateProperty(): void
    {
        $cmd = new UpdatePropertyCommand([
            'entity' => 'Camera',
            'component' => Transform2D::class,
            'property' => 'position',
            'value' => ['x' => 50, 'y' => 100],
        ]);
        $cmd->execute($this->context);

        $camera = $this->context->activeDocument->getEntity('Camera');
        $this->assertSame(['x' => 50, 'y' => 100], $camera['components'][0]['position']);
    }

    public function testGetEntityHierarchy(): void
    {
        $cmd = new GetEntityHierarchyCommand();
        $result = $cmd->execute($this->context);

        $this->assertArrayHasKey('entities', $result);
        $this->assertCount(1, $result['entities']);
        $this->assertSame('Camera', $result['entities'][0]['name']);
    }

    public function testCommandBusDispatch(): void
    {
        $bus = new EditorCommandBus($this->context);
        $bus->register('ListComponents', ListComponentsCommand::class);

        $result = $bus->dispatch('ListComponents');
        $this->assertArrayHasKey('components', $result);
    }

    public function testCommandBusUnknownThrows(): void
    {
        $bus = new EditorCommandBus($this->context);
        $this->expectException(\RuntimeException::class);
        $bus->dispatch('NonExistent');
    }
}
