<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Covers the fixed-timestep camera interpolation in {@see Camera3DSystem}.
 *
 * update() double-buffers the active camera's tick-end transform (prev + cur);
 * render() lerps/slerps between them by Engine::$renderInterpolation and emits a
 * SetCamera. Small per-tick moves interpolate; jumps > 5 units snap (no smear);
 * PHPOLYGON_NO_CAMERA_INTERP=1 disables interpolation entirely.
 *
 * The camera eye position is recovered from the emitted view matrix as the
 * translation of its inverse (a lookAt view maps the eye to the origin, so the
 * inverse maps the origin back to the eye).
 */
final class Camera3DSystemTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Snapshot the env var so each test starts from a known state and we
        // can restore the process env afterwards.
        $this->savedEnv['PHPOLYGON_NO_CAMERA_INTERP'] = getenv('PHPOLYGON_NO_CAMERA_INTERP');
        putenv('PHPOLYGON_NO_CAMERA_INTERP'); // unset -> interpolation on by default
    }

    protected function tearDown(): void
    {
        $val = $this->savedEnv['PHPOLYGON_NO_CAMERA_INTERP'] ?? false;
        if ($val === false) {
            putenv('PHPOLYGON_NO_CAMERA_INTERP');
        } else {
            putenv('PHPOLYGON_NO_CAMERA_INTERP=' . $val);
        }
    }

    private function headlessEngine(): Engine
    {
        // Headless EngineConfig keeps construction light: no window, no GL,
        // NullTextureManager. The only piece we exercise is the public
        // $renderInterpolation field.
        return new Engine(new EngineConfig(headless: true));
    }

    private function makeCameraEntity(World $world, Vec3 $pos): Entity
    {
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: $pos));
        $entity->attach(new Camera3DComponent(active: true));
        return $entity;
    }

    /** Move the camera transform to a new world position (refresh worldMatrix). */
    private function moveTo(Entity $entity, Vec3 $pos): void
    {
        $t = $entity->get(Transform3D::class);
        $t->position = $pos;
        // getWorldPosition() reads worldMatrix, which is only recomputed in the
        // ctor / hierarchy pass — refresh it so the system sees the new pose.
        $t->worldMatrix = $t->getLocalMatrix();
    }

    /** Recover the camera eye position encoded in a lookAt view matrix. */
    private function eyeFromView(Mat4 $view): Vec3
    {
        return $view->inverse()->getTranslation();
    }

    private function lastSetCamera(RenderCommandList $list): SetCamera
    {
        $cams = $list->ofType(SetCamera::class);
        $this->assertNotEmpty($cams, 'expected a SetCamera command to be emitted');
        return $cams[count($cams) - 1];
    }

    public function testInterpolatesToMidpointBetweenTicks(): void
    {
        $engine = $this->headlessEngine();
        $world = new World();
        $list = new RenderCommandList();
        $system = new \PHPolygon\System\Camera3DSystem($list, 800, 600, null, $engine);

        $a = new Vec3(0.0, 2.0, 0.0);
        $b = new Vec3(2.0, 2.0, 0.0); // 2-unit move -> below the 5-unit snap threshold

        $entity = $this->makeCameraEntity($world, $a);
        $system->update($world, 1.0 / 60.0); // first sample: prev == cur == A

        $this->moveTo($entity, $b);
        $system->update($world, 1.0 / 60.0); // now prev = A, cur = B

        $engine->renderInterpolation = 0.5;
        $system->render($world);

        $eye = $this->eyeFromView($this->lastSetCamera($list)->viewMatrix);
        $this->assertEqualsWithDelta(1.0, $eye->x, 1e-4); // midpoint of A and B
        $this->assertEqualsWithDelta(2.0, $eye->y, 1e-4);
        $this->assertEqualsWithDelta(0.0, $eye->z, 1e-4);
    }

    public function testAlphaZeroGivesPreviousPose(): void
    {
        $engine = $this->headlessEngine();
        $world = new World();
        $list = new RenderCommandList();
        $system = new \PHPolygon\System\Camera3DSystem($list, 800, 600, null, $engine);

        $a = new Vec3(0.0, 2.0, 0.0);
        $b = new Vec3(2.0, 2.0, 0.0);

        $entity = $this->makeCameraEntity($world, $a);
        $system->update($world, 1.0 / 60.0);
        $this->moveTo($entity, $b);
        $system->update($world, 1.0 / 60.0);

        $engine->renderInterpolation = 0.0;
        $system->render($world);

        $eye = $this->eyeFromView($this->lastSetCamera($list)->viewMatrix);
        $this->assertEqualsWithDelta(0.0, $eye->x, 1e-4); // == previous (A)
    }

    public function testTeleportJumpSnapsToCurrentPose(): void
    {
        $engine = $this->headlessEngine();
        $world = new World();
        $list = new RenderCommandList();
        $system = new \PHPolygon\System\Camera3DSystem($list, 800, 600, null, $engine);

        $a = new Vec3(0.0, 2.0, 0.0);
        $b = new Vec3(50.0, 2.0, 0.0); // 50-unit jump -> exceeds the 5-unit snap threshold

        $entity = $this->makeCameraEntity($world, $a);
        $system->update($world, 1.0 / 60.0);
        $this->moveTo($entity, $b);
        $system->update($world, 1.0 / 60.0);

        $engine->renderInterpolation = 0.5; // would be midpoint (25) if it lerped
        $system->render($world);

        $eye = $this->eyeFromView($this->lastSetCamera($list)->viewMatrix);
        $this->assertEqualsWithDelta(50.0, $eye->x, 1e-4); // snapped to current (B), not 25
    }

    public function testEnvVarDisablesInterpolation(): void
    {
        putenv('PHPOLYGON_NO_CAMERA_INTERP=1');

        $engine = $this->headlessEngine();
        $world = new World();
        $list = new RenderCommandList();
        // Must construct AFTER setting the env var — the system reads it in ctor.
        $system = new \PHPolygon\System\Camera3DSystem($list, 800, 600, null, $engine);

        $a = new Vec3(0.0, 2.0, 0.0);
        $b = new Vec3(2.0, 2.0, 0.0);

        $entity = $this->makeCameraEntity($world, $a);
        $system->update($world, 1.0 / 60.0); // no-op: interpolation disabled
        $this->moveTo($entity, $b);
        $system->update($world, 1.0 / 60.0);

        $engine->renderInterpolation = 0.5;
        $system->render($world);

        // With interpolation off, the rendered pose is the raw current transform (B),
        // never the midpoint.
        $eye = $this->eyeFromView($this->lastSetCamera($list)->viewMatrix);
        $this->assertEqualsWithDelta(2.0, $eye->x, 1e-4);
    }

    public function testNoEngineDisablesInterpolation(): void
    {
        // Without an Engine the system cannot read renderInterpolation, so it
        // must fall back to the raw transform (current pose).
        $world = new World();
        $list = new RenderCommandList();
        $system = new \PHPolygon\System\Camera3DSystem($list, 800, 600, null, null);

        $entity = $this->makeCameraEntity($world, new Vec3(0.0, 2.0, 0.0));
        $system->update($world, 1.0 / 60.0);
        $this->moveTo($entity, new Vec3(2.0, 2.0, 0.0));
        $system->update($world, 1.0 / 60.0);

        $system->render($world);

        $eye = $this->eyeFromView($this->lastSetCamera($list)->viewMatrix);
        $this->assertEqualsWithDelta(2.0, $eye->x, 1e-4);
    }
}
