<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SceneRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\ClearDepthBuffer;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\RenderLayer;
use PHPolygon\System\CompositingSystem;

class CompositingSystemTest extends TestCase
{
    public function testRenderLayerOrder(): void
    {
        $this->assertLessThan(RenderLayer::World3D->value, RenderLayer::Background3D->value);
        $this->assertLessThan(RenderLayer::Overlay2D->value, RenderLayer::World3D->value);
        $this->assertLessThan(RenderLayer::HUD2D->value, RenderLayer::Overlay2D->value);
    }

    public function testEmptyWorldEmitsNoCommands(): void
    {
        $commandList = new RenderCommandList();
        $system = new CompositingSystem($commandList);
        $world = new World();

        $system->render($world);

        $this->assertTrue($commandList->isEmpty());
    }

    public function testEntitiesRenderedInLayerOrder(): void
    {
        $commandList = new RenderCommandList();
        $system = new CompositingSystem($commandList);
        $world = new World();

        // HUD entity first (should render last)
        $hud = $world->createEntity();
        $hud->attach(new Transform3D());
        $hud->attach(new MeshRenderer('quad', 'hud_material'));
        $hud->attach(new SceneRenderer(RenderLayer::HUD2D));

        // Background entity second (should render first)
        $bg = $world->createEntity();
        $bg->attach(new Transform3D());
        $bg->attach(new MeshRenderer('skybox_mesh', 'sky_material'));
        $bg->attach(new SceneRenderer(RenderLayer::Background3D));

        // World entity third (should render in middle)
        $obj = $world->createEntity();
        $obj->attach(new Transform3D());
        $obj->attach(new MeshRenderer('box_1x1x1', 'stone'));
        $obj->attach(new SceneRenderer(RenderLayer::World3D));

        $system->render($world);

        $draws = $commandList->ofType(DrawMesh::class);
        $this->assertCount(3, $draws);

        // Background -> World -> HUD
        $this->assertSame('skybox_mesh', $draws[0]->meshId);
        $this->assertSame('box_1x1x1', $draws[1]->meshId);
        $this->assertSame('quad', $draws[2]->meshId);
    }

    public function testClearDepthInserted(): void
    {
        $commandList = new RenderCommandList();
        $system = new CompositingSystem($commandList);
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach(new MeshRenderer('quad', 'hud'));
        $entity->attach(new SceneRenderer(RenderLayer::HUD2D, clearDepth: true));

        $system->render($world);

        $commands = $commandList->getCommands();
        $this->assertCount(2, $commands);
        $this->assertInstanceOf(ClearDepthBuffer::class, $commands[0]);
        $this->assertInstanceOf(DrawMesh::class, $commands[1]);
    }

    public function testClearDepthOnlyOncePerLayer(): void
    {
        $commandList = new RenderCommandList();
        $system = new CompositingSystem($commandList);
        $world = new World();

        // Two entities in same layer, both requesting clearDepth
        $e1 = $world->createEntity();
        $e1->attach(new Transform3D());
        $e1->attach(new MeshRenderer('a', 'mat'));
        $e1->attach(new SceneRenderer(RenderLayer::Overlay2D, clearDepth: true));

        $e2 = $world->createEntity();
        $e2->attach(new Transform3D());
        $e2->attach(new MeshRenderer('b', 'mat'));
        $e2->attach(new SceneRenderer(RenderLayer::Overlay2D, clearDepth: true));

        $system->render($world);

        $clears = $commandList->ofType(ClearDepthBuffer::class);
        $this->assertCount(1, $clears);
    }

    public function testSortOrderWithinLayer(): void
    {
        $commandList = new RenderCommandList();
        $system = new CompositingSystem($commandList);
        $world = new World();

        // sortOrder 10 first
        $e1 = $world->createEntity();
        $e1->attach(new Transform3D());
        $e1->attach(new MeshRenderer('late', 'mat'));
        $e1->attach(new SceneRenderer(RenderLayer::World3D, sortOrder: 10));

        // sortOrder 1 second (should render first)
        $e2 = $world->createEntity();
        $e2->attach(new Transform3D());
        $e2->attach(new MeshRenderer('early', 'mat'));
        $e2->attach(new SceneRenderer(RenderLayer::World3D, sortOrder: 1));

        $system->render($world);

        $draws = $commandList->ofType(DrawMesh::class);
        $this->assertCount(2, $draws);
        $this->assertSame('early', $draws[0]->meshId);
        $this->assertSame('late', $draws[1]->meshId);
    }

    public function testDisabledSceneRendererSkipped(): void
    {
        $commandList = new RenderCommandList();
        $system = new CompositingSystem($commandList);
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach(new MeshRenderer('box', 'mat'));
        $entity->attach(new SceneRenderer(enabled: false));

        $system->render($world);

        $this->assertTrue($commandList->isEmpty());
    }

    public function testMultipleLayersWithClearDepth(): void
    {
        $commandList = new RenderCommandList();
        $system = new CompositingSystem($commandList);
        $world = new World();

        // World entity
        $w = $world->createEntity();
        $w->attach(new Transform3D());
        $w->attach(new MeshRenderer('world_mesh', 'mat'));
        $w->attach(new SceneRenderer(RenderLayer::World3D));

        // HUD entity with depth clear
        $h = $world->createEntity();
        $h->attach(new Transform3D());
        $h->attach(new MeshRenderer('hud_mesh', 'mat'));
        $h->attach(new SceneRenderer(RenderLayer::HUD2D, clearDepth: true));

        $system->render($world);

        $commands = $commandList->getCommands();
        // World draw, then ClearDepth, then HUD draw
        $this->assertCount(3, $commands);
        $this->assertInstanceOf(DrawMesh::class, $commands[0]);
        $this->assertSame('world_mesh', $commands[0]->meshId);
        $this->assertInstanceOf(ClearDepthBuffer::class, $commands[1]);
        $this->assertInstanceOf(DrawMesh::class, $commands[2]);
        $this->assertSame('hud_mesh', $commands[2]->meshId);
    }

    public function testSceneRendererDefaults(): void
    {
        $sr = new SceneRenderer();

        $this->assertSame(RenderLayer::World3D, $sr->renderLayer);
        $this->assertFalse($sr->clearDepth);
        $this->assertTrue($sr->enabled);
        $this->assertSame(0, $sr->sortOrder);
    }
}
