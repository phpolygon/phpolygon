<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PlanarMirror;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetPlanarMirror;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\RenderLayers;
use PHPolygon\System\PlanarMirrorSystem;
use PHPolygon\System\Renderer3DSystem;

/**
 * A planar mirror shows what lies behind the camera, which the camera's own cull dropped
 * before any command existed: with a mirror chosen, Renderer3DSystem adds those entities
 * as shadow-only draws without shadow; the mirror component picks the mirror of the frame;
 * a body hidden from the main camera becomes a shadow-only draw that casts.
 */
final class PlanarMirrorSystemTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    private static function camera(): SetCamera
    {
        // At z = 4 looking down -z.
        return new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.6, 4.0), new Vec3(0.0, 1.6, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 16.0 / 9.0, 0.1, 100.0),
        );
    }

    /**
     * @return list<object> the commands the renderer received
     */
    private function frame(World $world, bool $withMirrorSystem = true): array
    {
        $spy = new class extends NullRenderer3D {
            /** @var list<object> */
            public array $commands = [];
            public function render(RenderCommandList $commandList): void
            {
                $this->commands = $commandList->getCommands();
            }
        };
        $list = new RenderCommandList();
        $list->add(self::camera());
        $mirrors = new PlanarMirrorSystem($list);
        if ($withMirrorSystem) {
            $mirrors->render($world);
        }
        (new Renderer3DSystem($spy, $list))->render($world);

        return $spy->commands;
    }

    private static function addMirror(World $world, float $z = -3.0, ?Quaternion $rotation = null, ?Vec3 $scale = null): int
    {
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 1.6, $z), $rotation, $scale))
            ->attach(new PlanarMirror(2.0, 2.0, 0.5, 20.0));

        return $entity->id;
    }

    /** @return list<DrawMesh> */
    private static function draws(array $commands): array
    {
        return array_values(array_filter($commands, static fn($c) => $c instanceof DrawMesh));
    }

    public function testAnEntityBehindTheCameraAppearsOnlyWhenAMirrorIsChosen(): void
    {
        $world = new World();
        self::addMirror($world);
        $world->createEntity()->attach(new Transform3D(new Vec3(1.0, 1.0, 9.0)))->attach(new MeshRenderer('box', 'm'));
        $world->createEntity()->attach(new Transform3D(new Vec3(0.0, 1.0, 0.0)))->attach(new MeshRenderer('box', 'm'));

        $without = self::draws($this->frame($world, false));
        self::assertCount(1, $without, 'the camera sees only the box in front');
        self::assertFalse($without[0]->shadowOnly);

        $with = self::draws($this->frame($world));
        self::assertCount(2, $with);
        $extra = array_values(array_filter($with, static fn(DrawMesh $d) => $d->shadowOnly));
        self::assertCount(1, $extra, 'the box behind the camera is added for the mirror');
        self::assertFalse($extra[0]->castShadows, 'it adds no shadow');
        self::assertEqualsWithDelta(9.0, $extra[0]->modelMatrix->getTranslation()->z, 1e-9);
    }

    public function testTheSystemQueuesOneCommandWithTheComponentsPlane(): void
    {
        $world = new World();
        $id = self::addMirror($world, -3.0, null, new Vec3(1.5, 1.0, 1.0));

        $commands = $this->frame($world);
        $queued = array_values(array_filter($commands, static fn($c) => $c instanceof SetPlanarMirror));
        self::assertCount(1, $queued);
        self::assertEqualsWithDelta(1.0, $queued[0]->halfHeight, 1e-9);
        self::assertEqualsWithDelta(1.5, $queued[0]->halfWidth, 1e-9, 'world scale multiplies the rectangle');
        self::assertEqualsWithDelta(1.0, $queued[0]->normal->z, 1e-9, 'reflects toward local +Z');
        self::assertSame('e' . $id, $queued[0]->id);
    }

    public function testNoCommandWithoutAMirrorOrWhenBehindIt(): void
    {
        $world = new World();
        self::assertSame([], array_filter($this->frame($world), static fn($c) => $c instanceof SetPlanarMirror));

        // A mirror turned away from the camera.
        self::addMirror($world, -3.0, Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), M_PI));
        self::assertSame([], array_filter($this->frame($world), static fn($c) => $c instanceof SetPlanarMirror), 'seen from behind');

        $off = new World();
        $oid = self::addMirror($off);
        $off->getComponent($oid, PlanarMirror::class)->enabled = false;
        self::assertSame([], array_filter($this->frame($off), static fn($c) => $c instanceof SetPlanarMirror));
    }

    public function testTheMirrorMaskDecidesWhichLayersAreAdded(): void
    {
        $world = new World();
        $id = self::addMirror($world);
        $world->getComponent($id, PlanarMirror::class)->layerMask = RenderLayers::WORLD;
        $body = new MeshRenderer('box', 'm');
        $body->renderLayers = RenderLayers::BODY;
        $world->createEntity()->attach(new Transform3D(new Vec3(0.0, 1.0, 9.0)))->attach($body);
        $world->createEntity()->attach(new Transform3D(new Vec3(1.0, 1.0, 9.0)))->attach(new MeshRenderer('box', 'm'));

        $draws = self::draws($this->frame($world));
        self::assertCount(1, $draws, 'the body layer is outside the mirror mask');
        self::assertSame(RenderLayers::WORLD, $draws[0]->layers);
    }

    public function testAHiddenBodyBecomesAShadowOnlyDrawThatCastsAndKeepsItsLayer(): void
    {
        $world = new World();
        $body = new MeshRenderer('box', 'm');
        $body->hiddenFromMainCamera = true;
        $body->renderLayers = RenderLayers::BODY;
        $world->createEntity()->attach(new Transform3D(new Vec3(0.0, 1.0, 3.0)))->attach($body);
        $world->createEntity()->attach(new Transform3D(new Vec3(0.0, 1.0, 0.0)))->attach(new MeshRenderer('box', 'm'));

        $draws = self::draws($this->frame($world, false));
        self::assertCount(2, $draws);
        $hidden = array_values(array_filter($draws, static fn(DrawMesh $d) => $d->shadowOnly));
        self::assertCount(1, $hidden);
        self::assertTrue($hidden[0]->castShadows);
        self::assertSame(RenderLayers::BODY, $hidden[0]->layers);
    }

    public function testADefaultMeshRendererDrawsAsBefore(): void
    {
        $world = new World();
        $world->createEntity()->attach(new Transform3D(new Vec3(0.0, 1.0, 0.0)))->attach(new MeshRenderer('box', 'm'));
        $draws = self::draws($this->frame($world, false));
        self::assertCount(1, $draws);
        self::assertFalse($draws[0]->shadowOnly);
        self::assertTrue($draws[0]->castShadows);
        self::assertSame(RenderLayers::WORLD, $draws[0]->layers);
    }
}

