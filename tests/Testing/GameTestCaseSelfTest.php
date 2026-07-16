<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Testing;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Engine;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\Testing\GameTestCase;

/** Minimal 3D scene used to exercise the base class. */
class DemoScene extends Scene
{
    public function getName(): string
    {
        return 'demo';
    }

    public function build(SceneBuilder $builder): void
    {
        $builder->entity('Player')
            ->with(new Transform3D(position: new Vec3(0, 0, 0)))
            ->with(new MeshRenderer('demo_box', 'demo_mat'));
    }
}

/**
 * Self-test proving GameTestCase behaves as a game would use it. Doubles as the
 * usage example for downstream games.
 */
class GameTestCaseSelfTest extends GameTestCase
{
    protected function registerScenes(Engine $engine): void
    {
        MeshRegistry::register('demo_box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('demo_mat', new Material(albedo: new Color(0.8, 0.4, 0.2)));

        $engine->scenes->register('demo', DemoScene::class);
        // Games wire the renderer system with the shared command list; scene
        // getSystems() can't (it instantiates with no args).
        $engine->world->addSystem(new Renderer3DSystem($engine->renderer3D, new RenderCommandList()));
    }

    public function testHeadlessEngineIsReady(): void
    {
        // Headless 3D engines get a NullRenderer3D — a proxy for "no GPU".
        self::assertInstanceOf(
            \PHPolygon\Rendering\NullRenderer3D::class,
            $this->engine->renderer3D,
        );
    }

    public function testLoadSceneAndEntityAssertions(): void
    {
        $this->loadScene('demo');

        $this->assertSceneLoaded('demo');
        $this->assertEntityExists('demo', 'Player');
        $this->assertEntityCount(1);
    }

    public function testTickAdvancesWithoutError(): void
    {
        $this->loadScene('demo');
        $this->tick(times: 3);
        $this->assertEntityCount(1);
    }

    public function testRenderCommandsEmitDraw(): void
    {
        $this->loadScene('demo');
        $this->tick();

        $commands = $this->renderCommands();
        $this->assertDrawsMesh('demo_box', $commands);
        $this->assertMeshDrawCount(1, $commands);
    }
}
