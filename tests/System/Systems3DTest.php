<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Physics3DSystem;
use PHPolygon\System\Renderer3DSystem;

class Systems3DTest extends TestCase
{
    // ─── Camera3DSystem ───────────────────────────────────────────────────────

    public function testCamera3DSystemPushesSetCameraCommand(): void
    {
        $world = new World();
        $commandList = new RenderCommandList();

        $system = new Camera3DSystem($commandList, 1280, 720);
        $world->addSystem($system);

        $entity = $world->createEntity();
        $entity->attach(new Camera3DComponent(fov: 60.0, active: true));
        $entity->attach(new Transform3D(new Vec3(0.0, 0.0, 5.0)));

        $world->render();

        $cameras = $commandList->ofType(SetCamera::class);
        $this->assertCount(1, $cameras);
    }

    public function testCamera3DSystemViewMatrixIsNotIdentity(): void
    {
        $world = new World();
        $commandList = new RenderCommandList();

        $system = new Camera3DSystem($commandList, 1280, 720);
        $world->addSystem($system);

        $entity = $world->createEntity();
        $entity->attach(new Camera3DComponent());
        $entity->attach(new Transform3D(new Vec3(0.0, 5.0, 10.0)));

        $world->render();

        $cameras = $commandList->ofType(SetCamera::class);
        $this->assertCount(1, $cameras);

        // View matrix should not be identity (camera is offset)
        $view = $cameras[0]->viewMatrix->toArray();
        $identityArr = array_fill(0, 16, 0.0);
        $identityArr[0] = $identityArr[5] = $identityArr[10] = $identityArr[15] = 1.0;
        $this->assertNotEquals($identityArr, $view);
    }

    public function testCamera3DSystemInactiveCameraIsSkipped(): void
    {
        $world = new World();
        $commandList = new RenderCommandList();

        $system = new Camera3DSystem($commandList, 1280, 720);
        $world->addSystem($system);

        $entity = $world->createEntity();
        $entity->attach(new Camera3DComponent(active: false));
        $entity->attach(new Transform3D());

        $world->update(0.016);

        $this->assertTrue($commandList->isEmpty());
    }

    // ─── Renderer3DSystem ─────────────────────────────────────────────────────

    public function testRenderer3DSystemPushesDrawMeshCommand(): void
    {
        $world = new World();
        $renderer = new NullRenderer3D();
        $commandList = new RenderCommandList();

        $system = new Renderer3DSystem($renderer, $commandList);
        $world->addSystem($system);

        $entity = $world->createEntity();
        $entity->attach(new MeshRenderer('box', 'stone'));
        $entity->attach(new Transform3D());

        $world->render();

        // After render(), commandList is cleared — check what renderer received
        $lastList = $renderer->getLastCommandList();
        $this->assertNotNull($lastList);
        $draws = $lastList->ofType(DrawMesh::class);
        $this->assertCount(1, $draws);
        $this->assertEquals('box', $draws[0]->meshId);
        $this->assertEquals('stone', $draws[0]->materialId);
    }

    public function testRenderer3DSystemClearsCommandListAfterFlush(): void
    {
        $world = new World();
        $renderer = new NullRenderer3D();
        $commandList = new RenderCommandList();

        $system = new Renderer3DSystem($renderer, $commandList);
        $world->addSystem($system);

        $entity = $world->createEntity();
        $entity->attach(new MeshRenderer('box', 'stone'));
        $entity->attach(new Transform3D());

        $world->render();

        // Command list should be empty after flush
        $this->assertTrue($commandList->isEmpty());
    }

    public function testRenderer3DSystemPushesDirectionalLightCommand(): void
    {
        $world = new World();
        $renderer = new NullRenderer3D();
        $commandList = new RenderCommandList();

        $system = new Renderer3DSystem($renderer, $commandList);
        $world->addSystem($system);

        $entity = $world->createEntity();
        $entity->attach(new DirectionalLight(new Vec3(0.0, -1.0, 0.0)));
        $entity->attach(new Transform3D());

        $world->render();

        $lastList = $renderer->getLastCommandList();
        $this->assertNotNull($lastList);
        $lights = $lastList->ofType(SetDirectionalLight::class);
        $this->assertCount(1, $lights);
    }

    // ─── Physics3DSystem ──────────────────────────────────────────────────────

    public function testPhysics3DGravityMovesEntityDown(): void
    {
        $world = new World();
        $system = new Physics3DSystem();
        $world->addSystem($system);

        $entity = $world->createEntity();
        $entity->attach(new CharacterController3D());
        $entity->attach(new Transform3D(new Vec3(0.0, 100.0, 0.0)));

        $world->update(1.0);

        $transform = $entity->get(Transform3D::class);
        $this->assertLessThan(100.0, $transform->position->y);
    }

    public function testPhysics3DIsGroundedStartsFalse(): void
    {
        $cc = new CharacterController3D();
        $this->assertFalse($cc->isGrounded);
    }

    public function testPhysics3DSetAndGetGravity(): void
    {
        $system = new Physics3DSystem();
        $customGravity = new Vec3(0.0, -20.0, 0.0);
        $system->setGravity($customGravity);
        $this->assertTrue($system->getGravity()->equals($customGravity));
    }

    public function testPhysics3DGroundDetection(): void
    {
        $world = new World();
        $system = new Physics3DSystem();
        $world->addSystem($system);

        // Place entity just above the floor
        $entity = $world->createEntity();
        $cc = new CharacterController3D(height: 2.0);
        $entity->attach($cc);
        $entity->attach(new Transform3D(new Vec3(0.0, 1.0, 0.0)));

        // After several ticks, entity should land and isGrounded = true
        for ($i = 0; $i < 60; $i++) {
            $world->update(0.016);
        }

        $this->assertTrue($cc->isGrounded);
    }
}
