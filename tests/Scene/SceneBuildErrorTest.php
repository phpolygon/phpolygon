<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\SceneBuildException;

/** System registered by the failing scene — used to assert rollback. */
class RollbackProbeSystem extends AbstractSystem
{
    public function update(World $world, float $deltaTime): void {}
}

/** build() throws after registering a system, mimicking a fatal author error. */
class ThrowingScene extends Scene
{
    public function getName(): string
    {
        return 'throwing_scene';
    }

    /** @return list<class-string<\PHPolygon\ECS\SystemInterface>> */
    public function getSystems(): array
    {
        return [RollbackProbeSystem::class];
    }

    public function build(SceneBuilder $builder): void
    {
        $builder->entity('Half')->with(new Transform2D());
        throw new \RuntimeException('boom in build');
    }
}

class SceneBuildErrorTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine(new EngineConfig(headless: true));
    }

    public function testBuildFailureThrowsSceneBuildException(): void
    {
        $this->engine->scenes->register('throwing', ThrowingScene::class);

        try {
            $this->engine->scenes->loadScene('throwing');
            self::fail('Expected SceneBuildException');
        } catch (SceneBuildException $e) {
            self::assertSame('throwing', $e->sceneName);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertStringContainsString('boom in build', (string) $e->getPrevious()?->getMessage());
        }
    }

    public function testBuildFailureDoesNotLeaveSceneLoaded(): void
    {
        $this->engine->scenes->register('throwing', ThrowingScene::class);

        try {
            $this->engine->scenes->loadScene('throwing');
        } catch (SceneBuildException) {
            // expected
        }

        self::assertFalse($this->engine->scenes->isLoaded('throwing'));
        self::assertNull($this->engine->scenes->getActiveScene());
    }

    public function testBuildFailureRollsBackRegisteredSystems(): void
    {
        $systemsBefore = count($this->engine->world->getSystems());
        $this->engine->scenes->register('throwing', ThrowingScene::class);

        try {
            $this->engine->scenes->loadScene('throwing');
        } catch (SceneBuildException) {
            // expected
        }

        self::assertCount(
            $systemsBefore,
            $this->engine->world->getSystems(),
            'The failing scene\'s system must be removed from the world on rollback',
        );
    }
}
