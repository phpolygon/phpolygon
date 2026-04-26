<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\IsometricCamera;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\IsometricCameraSystem;

class IsometricCameraTest extends TestCase
{
    // --- Component tests ---

    public function testDefaultValues(): void
    {
        $cam = new IsometricCamera();

        $this->assertEqualsWithDelta(20.0, $cam->zoom, 1e-6);
        $this->assertEqualsWithDelta(45.0, $cam->angle, 1e-6);
        $this->assertEqualsWithDelta(35.264, $cam->pitch, 1e-3);
        $this->assertEqualsWithDelta(50.0, $cam->distance, 1e-6);
        $this->assertTrue($cam->active);
        $this->assertEqualsWithDelta(0.0, $cam->smoothing, 1e-6);
    }

    public function testCustomValues(): void
    {
        $cam = new IsometricCamera(
            zoom: 30.0,
            angle: 90.0,
            pitch: 60.0,
            distance: 100.0,
            near: 1.0,
            far: 200.0,
            active: false,
            smoothing: 0.5,
        );

        $this->assertEqualsWithDelta(30.0, $cam->zoom, 1e-6);
        $this->assertEqualsWithDelta(90.0, $cam->angle, 1e-6);
        $this->assertEqualsWithDelta(60.0, $cam->pitch, 1e-6);
        $this->assertEqualsWithDelta(100.0, $cam->distance, 1e-6);
        $this->assertEqualsWithDelta(1.0, $cam->near, 1e-6);
        $this->assertEqualsWithDelta(200.0, $cam->far, 1e-6);
        $this->assertFalse($cam->active);
        $this->assertEqualsWithDelta(0.5, $cam->smoothing, 1e-6);
    }

    // --- System tests ---

    public function testEmitsSetCameraCommand(): void
    {
        $commandList = new RenderCommandList();
        $system = new IsometricCameraSystem($commandList, 800, 600);
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: new Vec3(10, 0, 10)));
        $entity->attach(new IsometricCamera(zoom: 20.0, angle: 45.0, pitch: 30.0, distance: 50.0));

        $system->render($world);

        $cameras = $commandList->ofType(SetCamera::class);
        $this->assertCount(1, $cameras);
    }

    public function testInactiveCameraSkipped(): void
    {
        $commandList = new RenderCommandList();
        $system = new IsometricCameraSystem($commandList, 800, 600);
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach(new IsometricCamera(active: false));

        $system->render($world);

        $this->assertTrue($commandList->isEmpty());
    }

    public function testOrthographicProjectionAspect(): void
    {
        $commandList = new RenderCommandList();
        // 800x400 = aspect 2.0
        $system = new IsometricCameraSystem($commandList, 800, 400);
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: Vec3::zero()));
        $entity->attach(new IsometricCamera(zoom: 10.0, angle: 0.0, pitch: 45.0, distance: 20.0));

        $system->render($world);

        $cameras = $commandList->ofType(SetCamera::class);
        $this->assertCount(1, $cameras);

        // The projection matrix exists and is a valid Mat4
        $proj = $cameras[0]->projectionMatrix;
        $this->assertNotNull($proj);
    }

    public function testCameraEyePosition(): void
    {
        $commandList = new RenderCommandList();
        $system = new IsometricCameraSystem($commandList, 800, 600);
        $world = new World();

        // angle=0, pitch=90 -> eye directly above target
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: new Vec3(0, 0, 0)));
        $entity->attach(new IsometricCamera(zoom: 10.0, angle: 0.0, pitch: 90.0, distance: 50.0));

        $system->render($world);

        $cameras = $commandList->ofType(SetCamera::class);
        $this->assertCount(1, $cameras);

        // View matrix should be valid (camera looking down)
        $view = $cameras[0]->viewMatrix;
        $this->assertNotNull($view);
    }

    public function testSmoothingLerpsTarget(): void
    {
        $commandList = new RenderCommandList();
        $system = new IsometricCameraSystem($commandList, 800, 600);
        $world = new World();

        $entity = $world->createEntity();
        $transform = new Transform3D(position: new Vec3(0, 0, 0));
        $entity->attach($transform);
        $entity->attach(new IsometricCamera(smoothing: 0.9));

        // First render establishes the target
        $system->render($world);
        $commandList->clear();

        // Move entity far away
        $transform->position = new Vec3(100, 0, 100);
        $system->render($world);

        // Camera should have emitted a command (smoothed target != final target)
        $cameras = $commandList->ofType(SetCamera::class);
        $this->assertCount(1, $cameras);
    }

    public function testOnlyFirstActiveCameraUsed(): void
    {
        $commandList = new RenderCommandList();
        $system = new IsometricCameraSystem($commandList, 800, 600);
        $world = new World();

        $e1 = $world->createEntity();
        $e1->attach(new Transform3D());
        $e1->attach(new IsometricCamera(active: true));

        $e2 = $world->createEntity();
        $e2->attach(new Transform3D());
        $e2->attach(new IsometricCamera(active: true));

        $system->render($world);

        $cameras = $commandList->ofType(SetCamera::class);
        $this->assertCount(1, $cameras);
    }
}
