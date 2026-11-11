<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

/**
 * Phase 3's shadow-correctness fix: a caster outside the camera's view but
 * inside the sun's caster volume gets an EXTRA DrawMesh(shadowOnly: true)
 * (see VisibilityService::shadowOnlyEntities()) so VioRenderer3D's shadow
 * pass - which reads the full, unfiltered command list - finds it. Before
 * this, an off-screen caster never got a draw command at all.
 */
final class Renderer3DSystemShadowOnlyTest extends TestCase
{
    /** @var list<object> */
    private array $commands = [];

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        $this->commands = [];
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    private static function camera(): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    /** @return list<DrawMesh> */
    private function render(World $world): array
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
        (new Renderer3DSystem($spy, $list))->render($world);
        $this->commands = $spy->commands;

        $draws = [];
        foreach ($this->commands as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $draws[] = $cmd;
            }
        }
        return $draws;
    }

    public function testOffScreenCasterGetsAShadowOnlyDraw(): void
    {
        $world = new World();
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        // Behind the camera (z=10, looking down -Z from the origin).
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $draws = $this->render($world);

        self::assertCount(1, $draws);
        self::assertTrue($draws[0]->shadowOnly);
        self::assertTrue($draws[0]->castShadows);
    }

    public function testVisibleCasterDrawsNormallyWithoutADuplicateShadowOnlyDraw(): void
    {
        $world = new World();
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0))) // in view
            ->attach(new MeshRenderer('box', 'm'));

        $draws = $this->render($world);

        self::assertCount(1, $draws, 'exactly one draw - no extra shadow-only duplicate of an already-visible entity');
        self::assertFalse($draws[0]->shadowOnly);
    }

    public function testNonShadowCastingMeshNeverGetsAShadowOnlyDraw(): void
    {
        $world = new World();
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm', castShadows: false));

        $draws = $this->render($world);

        self::assertSame([], $draws);
    }

    public function testNoDirectionalLightMeansNoShadowOnlyDraws(): void
    {
        $world = new World();
        // Same off-screen entity, no DirectionalLight this time.
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $draws = $this->render($world);

        self::assertSame([], $draws);
    }

    public function testHiddenMeshRendererNeverGetsAShadowOnlyDrawEither(): void
    {
        $world = new World();
        $world->createEntity()->attach(new DirectionalLight(
            direction: new Vec3(0.0, -1.0, 0.0),
            color: new Color(1.0, 1.0, 1.0),
            intensity: 1.0,
        ));
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm', visible: false));

        $draws = $this->render($world);

        self::assertSame([], $draws, 'MeshRenderer::$visible = false removes the entry from the index entirely');
    }
}
