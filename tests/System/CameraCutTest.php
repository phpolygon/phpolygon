<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\IsometricCamera;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\CameraCutDetector;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\IsometricCameraSystem;

/**
 * Temporal passes reproject last frame's image; across a teleport or a switch
 * to another camera that history is garbage. The camera systems flag such a
 * frame with SetCamera::$cut so the renderer can drop its history - exactly
 * once, on the first frame after the discontinuity.
 */
final class CameraCutTest extends TestCase
{
    /** @var string|false */
    private string|false $savedInterp = false;

    protected function setUp(): void
    {
        $this->savedInterp = getenv('PHPOLYGON_NO_CAMERA_INTERP');
        putenv('PHPOLYGON_NO_CAMERA_INTERP');
    }

    protected function tearDown(): void
    {
        putenv($this->savedInterp === false ? 'PHPOLYGON_NO_CAMERA_INTERP' : 'PHPOLYGON_NO_CAMERA_INTERP=' . $this->savedInterp);
    }

    public function testSetCameraIsNoCutByDefault(): void
    {
        $this->assertFalse((new SetCamera(Mat4::identity(), Mat4::identity()))->cut);
    }

    public function testDetectorCutsOnFirstFrameTeleportAndCameraSwitchOnly(): void
    {
        $detector = new CameraCutDetector();

        $this->assertTrue($detector->observe(1, new Vec3(0.0, 0.0, 0.0)), 'no history yet');
        $this->assertFalse($detector->observe(1, new Vec3(1.0, 0.0, 0.0)));
        $this->assertTrue($detector->observe(1, new Vec3(40.0, 0.0, 0.0)), 'teleport');
        $this->assertFalse($detector->observe(1, new Vec3(40.5, 0.0, 0.0)), 'cut fires once');
        $this->assertTrue($detector->observe(2, new Vec3(40.5, 0.0, 0.0)), 'other camera');

        $detector->reset();
        $this->assertTrue($detector->observe(2, new Vec3(40.5, 0.0, 0.0)), 'reset forgets the history');
    }

    public function testInterpolatedCameraCutsOnceOnTeleport(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));
        $world = new World();
        $list = new RenderCommandList();
        $system = new Camera3DSystem($list, 800, 600, null, $engine);
        $cam = $this->camera($world, new Vec3(0.0, 2.0, 0.0));

        $system->update($world, 1.0 / 60.0);
        $this->assertTrue($this->renderCut($system, $world, $list), 'first frame has no history');

        $this->moveTo($cam, new Vec3(1.0, 2.0, 0.0));
        $system->update($world, 1.0 / 60.0);
        $engine->renderInterpolation = 0.5;
        $this->assertFalse($this->renderCut($system, $world, $list), 'smooth motion is no cut');
        $engine->renderInterpolation = 1.0;
        $this->assertFalse($this->renderCut($system, $world, $list));

        $this->moveTo($cam, new Vec3(80.0, 2.0, 0.0));
        $system->update($world, 1.0 / 60.0);
        $engine->renderInterpolation = 0.3;
        $this->assertTrue($this->renderCut($system, $world, $list), 'teleport snaps and cuts');
        $engine->renderInterpolation = 0.8;
        $this->assertFalse($this->renderCut($system, $world, $list), 'second render of the same tick is no cut');
    }

    public function testUninterpolatedCameraCutsOnTeleportAndOnSwitch(): void
    {
        putenv('PHPOLYGON_NO_CAMERA_INTERP=1');
        $world = new World();
        $list = new RenderCommandList();
        $system = new Camera3DSystem($list, 800, 600);
        $a = $this->camera($world, new Vec3(0.0, 2.0, 0.0));

        $this->renderCut($system, $world, $list);
        $this->moveTo($a, new Vec3(0.5, 2.0, 0.0));
        $this->assertFalse($this->renderCut($system, $world, $list));
        $this->moveTo($a, new Vec3(0.5, 2.0, 60.0));
        $this->assertTrue($this->renderCut($system, $world, $list));

        $a->get(Camera3DComponent::class)->active = false;
        $this->camera($world, new Vec3(0.5, 2.0, 60.0));
        $this->assertTrue($this->renderCut($system, $world, $list), 'switching cameras breaks history');
    }

    public function testWorldClearMakesTheNextFrameACut(): void
    {
        putenv('PHPOLYGON_NO_CAMERA_INTERP=1');
        $world = new World();
        $list = new RenderCommandList();
        $system = new Camera3DSystem($list, 800, 600);
        $this->camera($world, new Vec3(0.0, 2.0, 0.0));
        $this->renderCut($system, $world, $list);

        $world->clear();
        $system->onWorldClear($world);
        $this->camera($world, new Vec3(0.0, 2.0, 0.0)); // same id, same place, new scene

        $this->assertTrue($this->renderCut($system, $world, $list));
    }

    public function testIsometricCameraCutsOnFirstFrameAndSwitch(): void
    {
        $world = new World();
        $list = new RenderCommandList();
        $system = new IsometricCameraSystem($list, 800, 600);
        $first = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new IsometricCamera(smoothing: 0.0));

        $this->assertTrue($this->renderCut($system, $world, $list));
        $this->assertFalse($this->renderCut($system, $world, $list));

        $first->get(IsometricCamera::class)->active = false;
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new IsometricCamera(smoothing: 0.0));
        $this->assertTrue($this->renderCut($system, $world, $list));
    }

    private function camera(World $world, Vec3 $at): Entity
    {
        return $world->createEntity()
            ->attach(new Transform3D(position: $at))
            ->attach(new Camera3DComponent(active: true));
    }

    private function moveTo(Entity $entity, Vec3 $pos): void
    {
        $t = $entity->get(Transform3D::class);
        $t->position = $pos;
        $t->worldMatrix = $t->getLocalMatrix();
    }

    private function renderCut(Camera3DSystem|IsometricCameraSystem $system, World $world, RenderCommandList $list): bool
    {
        $list->clear();
        $system->render($world);
        $cams = $list->ofType(SetCamera::class);
        $this->assertCount(1, $cams);
        return $cams[0]->cut;
    }
}
