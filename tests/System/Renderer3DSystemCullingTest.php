<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

/**
 * Culling and selection in Renderer3DSystem: castShadows reaches the draw,
 * moving entities are not culled by their stale spatial bin, the frustum test
 * uses the world scale, and point lights are chosen by their effect on the
 * view rather than by the camera's distance alone.
 */
final class Renderer3DSystemCullingTest extends TestCase
{
    private NullRenderer3D $spy;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        $this->spy = new class extends NullRenderer3D {
            /** @var list<object> */
            public array $commands = [];

            public function render(RenderCommandList $commandList): void
            {
                $this->commands = $commandList->getCommands();
            }
        };
    }

    /** Eye at $eye looking down -Z, 60 degree square frustum. */
    private static function camera(Vec3 $eye = new Vec3(0.0, 0.0, 0.0)): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt($eye, new Vec3($eye->x, $eye->y, $eye->z - 1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    /** @return list<DrawMesh> */
    private function frame(Renderer3DSystem $system, RenderCommandList $list, World $world, ?SetCamera $camera = null): array
    {
        $list->add($camera ?? self::camera());
        $system->render($world);
        $draws = [];
        foreach ($this->spy->commands as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $draws[] = $cmd;
            }
        }
        return $draws;
    }

    /** @return list<AddPointLight> */
    private function lights(): array
    {
        $out = [];
        foreach ($this->spy->commands as $cmd) {
            if ($cmd instanceof AddPointLight) {
                $out[] = $cmd;
            }
        }
        return $out;
    }

    public function testCastShadowsReachesTheDrawCommand(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -5.0)))
            ->attach(new MeshRenderer('box', 'm', castShadows: false));
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(1.0, 0.0, -5.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $list = new RenderCommandList();

        $draws = $this->frame(new Renderer3DSystem($this->spy, $list), $list, $world);

        self::assertCount(2, $draws);
        self::assertFalse($draws[0]->castShadows);
        self::assertTrue($draws[1]->castShadows);
    }

    public function testMovingEntityIsNotCulledByItsStaleBin(): void
    {
        $world = new World();
        $transform = new Transform3D(new Vec3(600.0, 0.0, -10.0));
        $world->createEntity()->attach($transform)->attach(new MeshRenderer('box', 'm'));
        $list = new RenderCommandList();
        $system = new Renderer3DSystem($this->spy, $list);

        // Frame 1: far off to the side - binned there, outside the frustum.
        self::assertSame([], $this->frame($system, $list, $world));

        // It drives into view; the bins are rebuilt only every 30 frames, so
        // its bin still says "out of view".
        $transform->worldMatrix = Mat4::translation(0.0, 0.0, -10.0);
        self::assertCount(1, $this->frame($system, $list, $world), 'the mover is drawn the frame it enters the view');
    }

    public function testStaticEntityInCulledBinStaysCulled(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(600.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $list = new RenderCommandList();
        $system = new Renderer3DSystem($this->spy, $list);

        self::assertSame([], $this->frame($system, $list, $world));
        self::assertSame([], $this->frame($system, $list, $world));
    }

    public function testFrustumRadiusUsesTheWorldScale(): void
    {
        $world = new World();
        // A unit box whose WORLD matrix scales it by 10 (e.g. under a scaled
        // parent) while its own Transform3D scale is 1. Its centre lies ~3 m
        // outside the left frustum plane; the scaled box reaches well inside.
        $transform = new Transform3D(new Vec3(-15.0, 0.0, -20.0));
        $transform->worldMatrix = Mat4::translation(-15.0, 0.0, -20.0)->multiply(Mat4::scaling(10.0, 10.0, 10.0));
        $world->createEntity()->attach($transform)->attach(new MeshRenderer('box', 'm'));
        $list = new RenderCommandList();

        $draws = $this->frame(new Renderer3DSystem($this->spy, $list), $list, $world);

        self::assertCount(1, $draws, 'the world-scaled box intersects the frustum');
    }

    public function testLightWhoseSphereMissesTheFrustumTakesNoSlot(): void
    {
        $world = new World();
        // Behind the camera, radius 5: lights nothing the camera can see.
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 20.0)))
            ->attach(new PointLight(intensity: 2.0, radius: 5.0));
        // Behind the camera too, but its 30 m sphere reaches into the view.
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 20.0)))
            ->attach(new PointLight(intensity: 2.0, radius: 30.0));
        $list = new RenderCommandList();

        $this->frame(new Renderer3DSystem($this->spy, $list), $list, $world);

        $lights = $this->lights();
        self::assertCount(1, $lights);
        self::assertSame(30.0, $lights[0]->radius);
    }

    public function testLightOnTheViewedWallWinsOverNearerLightsThatReachNothingCloser(): void
    {
        $world = new World();
        // Eight weak lights near the camera, each 3 m from it with radius 1:
        // they light little around the camera.
        for ($i = 0; $i < 8; $i++) {
            $world->createEntity()
                ->attach(new Transform3D(new Vec3(-2.0 + $i * 0.5, 0.0, -3.0)))
                ->attach(new PointLight(intensity: 1.0, radius: 0.5));
        }
        // A wall lamp 6 m ahead whose 10 m radius contains the camera.
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -6.0)))
            ->attach(new PointLight(intensity: 1.0, radius: 10.0));
        $list = new RenderCommandList();

        $this->frame(new Renderer3DSystem($this->spy, $list), $list, $world);

        $radii = array_map(static fn (AddPointLight $l): float => $l->radius, $this->lights());
        self::assertCount(Renderer3DSystem::MAX_POINT_LIGHTS, $radii);
        self::assertContains(10.0, $radii, 'the light whose sphere holds the camera keeps its slot');
    }

    public function testLightRankingUsesTheEyeOfTheRenderedCamera(): void
    {
        $world = new World();
        // The camera ENTITY stands at the origin; the eye (SetCamera, eye
        // offset applied) is 40 m back at z = +40, looking down -Z.
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new Camera3DComponent(eyeOffset: new Vec3(0.0, 0.0, 40.0)));
        // Eight lights in view, around the entity position (42 m from the eye).
        for ($i = 0; $i < 8; $i++) {
            $world->createEntity()
                ->attach(new Transform3D(new Vec3(-2.0 + $i * 0.5, 0.0, -2.0)))
                ->attach(new PointLight(intensity: 1.0, radius: 1.0));
        }
        // One light 5 m in front of the eye (35 m from the entity).
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 35.0)))
            ->attach(new PointLight(intensity: 1.0, radius: 2.0));
        $list = new RenderCommandList();

        $this->frame(new Renderer3DSystem($this->spy, $list), $list, $world, self::camera(new Vec3(0.0, 0.0, 40.0)));

        $zs = array_map(static fn (AddPointLight $l): float => $l->position->z, $this->lights());
        self::assertContains(35.0, $zs, 'the light next to the eye is emitted, not dropped for lights near the body');
    }
}
