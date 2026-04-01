<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Math\Vec2;
use PHPolygon\Scene\LoadMode;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\SceneManager;

class TestScene extends Scene
{
    public function getName(): string
    {
        return 'test_scene';
    }

    public function build(SceneBuilder $builder): void
    {
        $builder->entity('Camera')
            ->with(new Transform2D());

        $builder->entity('Player')
            ->with(new Transform2D(position: new Vec2(100, 200)));
    }
}

class TestSceneWithPersistent extends Scene
{
    public function getName(): string
    {
        return 'persistent_scene';
    }

    public function build(SceneBuilder $builder): void
    {
        $builder->entity('GameManager')
            ->with(new Transform2D())
            ->persist();

        $builder->entity('Temporary')
            ->with(new Transform2D());
    }
}

class SecondScene extends Scene
{
    public function getName(): string
    {
        return 'second_scene';
    }

    public function build(SceneBuilder $builder): void
    {
        $builder->entity('Enemy')
            ->with(new Transform2D(position: new Vec2(50, 50)));
    }
}

/**
 * @note These tests create Engine without calling run() (no GL context needed).
 *       We access world and scenes directly.
 */
class SceneManagerTest extends TestCase
{
    private Engine $engine;
    private SceneManager $scenes;

    protected function setUp(): void
    {
        $this->engine = new Engine(new EngineConfig(headless: true));
        $this->scenes = $this->engine->scenes;
    }

    public function testRegisterAndLoad(): void
    {
        $this->scenes->register('test', TestScene::class);
        $this->scenes->loadScene('test');

        $this->assertTrue($this->scenes->isLoaded('test'));
        $active = $this->scenes->getActiveScene();
        $this->assertNotNull($active);
        $this->assertSame('test_scene', $active->getName());

        // Entities created in world
        $this->assertEquals(2, $this->engine->world->entityCount());
    }

    public function testUnloadScene(): void
    {
        $this->scenes->register('test', TestScene::class);
        $this->scenes->loadScene('test');
        $this->scenes->unloadScene('test');

        $this->assertFalse($this->scenes->isLoaded('test'));
        $this->assertNull($this->scenes->getActiveScene());
        $this->assertEquals(0, $this->engine->world->entityCount());
    }

    public function testSingleModeReplacesScene(): void
    {
        $this->scenes->register('first', TestScene::class);
        $this->scenes->register('second', SecondScene::class);

        $this->scenes->loadScene('first');
        $this->assertEquals(2, $this->engine->world->entityCount());

        $this->scenes->loadScene('second', LoadMode::Single);
        $this->assertFalse($this->scenes->isLoaded('first'));
        $this->assertTrue($this->scenes->isLoaded('second'));
        $this->assertEquals(1, $this->engine->world->entityCount());
    }

    public function testAdditiveModeKeepsBothScenes(): void
    {
        $this->scenes->register('first', TestScene::class);
        $this->scenes->register('second', SecondScene::class);

        $this->scenes->loadScene('first');
        $this->scenes->loadScene('second', LoadMode::Additive);

        $this->assertTrue($this->scenes->isLoaded('first'));
        $this->assertTrue($this->scenes->isLoaded('second'));
        $this->assertEquals(3, $this->engine->world->entityCount());
    }

    public function testPersistentEntitiesSurviveSceneSwitch(): void
    {
        $this->scenes->register('persistent', TestSceneWithPersistent::class);
        $this->scenes->register('second', SecondScene::class);

        $this->scenes->loadScene('persistent');
        $this->assertEquals(2, $this->engine->world->entityCount());

        $entityMap = $this->scenes->getSceneEntities('persistent');
        $gameManagerId = $entityMap['GameManager'];
        $this->assertTrue($this->scenes->isPersistent($gameManagerId));

        // Switch to second scene — persistent entity survives
        $this->scenes->loadScene('second', LoadMode::Single);

        // GameManager (persistent) + Enemy = 2
        // Temporary was destroyed
        $this->assertTrue($this->engine->world->isAlive($gameManagerId));
        $this->assertEquals(2, $this->engine->world->entityCount());
    }

    public function testLoadUnregisteredSceneThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Scene 'missing' is not registered");
        $this->scenes->loadScene('missing');
    }

    public function testGetSceneEntitiesMap(): void
    {
        $this->scenes->register('test', TestScene::class);
        $this->scenes->loadScene('test');

        $map = $this->scenes->getSceneEntities('test');
        $this->assertNotNull($map);
        $this->assertArrayHasKey('Camera', $map);
        $this->assertArrayHasKey('Player', $map);
    }

    public function testMarkPersistentAtRuntime(): void
    {
        $this->scenes->register('test', TestScene::class);
        $this->scenes->register('second', SecondScene::class);

        $this->scenes->loadScene('test');
        $map = $this->scenes->getSceneEntities('test');
        $playerId = $map['Player'];

        // Mark player as persistent at runtime
        $this->scenes->markPersistent($playerId);

        $this->scenes->loadScene('second', LoadMode::Single);

        $this->assertTrue($this->engine->world->isAlive($playerId));
    }
}
