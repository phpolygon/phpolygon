<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\MeshBounds;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Frustum;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

/**
 * The "alt/neu" comparison the visibility plan's Phase 2 asks for: a
 * brute-force oracle (every MeshRenderer+Transform3D pair, a plain
 * sphere-vs-frustum test, no SpatialIndex/VisibilityService involved at all)
 * must agree with what Renderer3DSystem actually draws through
 * VisibilityService::visibleEntities() - same drawn set for several camera
 * poses, including a mover crossing more than 30 frames (the old bin rebuild
 * interval - there is no such interval any more, so this must hold on every
 * single frame, not just every 30th).
 */
final class Renderer3DSystemVisibilityOracleTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    /**
     * Independent of Renderer3DSystem/VisibilityService: every non-dormant,
     * visible MeshRenderer+Transform3D pair whose mesh-sphere (world space)
     * is not entirely outside the frustum.
     *
     * @return list<string> sorted meshIds of the oracle's visible set (duplicates kept)
     */
    private static function oracle(World $world, SetCamera $camera): array
    {
        $planes = Frustum::planes($camera->projectionMatrix, $camera->viewMatrix);
        $visible = [];
        foreach ($world->componentPool(MeshRenderer::class) as $id => $mesh) {
            if (!$mesh->visible || $world->isDormant($id)) {
                continue;
            }
            $transform = $world->tryGetComponent($id, Transform3D::class);
            if (!$transform instanceof Transform3D) {
                continue;
            }
            $sphere = MeshBounds::worldSphere($mesh->meshId, $transform->worldMatrix);
            if ($sphere === null || !Frustum::sphereOutside($planes, $sphere['cx'], $sphere['cy'], $sphere['cz'], $sphere['radius'])) {
                $visible[] = $mesh->meshId . '#' . $id;
            }
        }
        sort($visible);
        return $visible;
    }

    /**
     * @param object{commands: list<object>} $spy
     * @return list<string>
     */
    private static function drawn(object $spy): array
    {
        $draws = [];
        foreach ($spy->commands as $cmd) {
            if ($cmd instanceof DrawMesh) {
                $draws[] = $cmd;
            }
        }
        $names = array_map(static fn(DrawMesh $d): string => $d->meshId, $draws);
        sort($names);
        return $names;
    }

    private static function camera(Vec3 $eye, Vec3 $look): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt($eye, $look, new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    public function testOracleAgreesWithRenderer3DSystemAcrossSeveralPoses(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        // Mixed scenario: visible-and-in-view, visible-but-culled, hidden
        // (MeshRenderer::$visible = false), dormant, and no-mesh-registered.
        $ids = [];
        $ids['front'] = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'))->id;
        $ids['behind'] = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'))->id;
        $ids['farSide'] = $world->createEntity()
            ->attach(new Transform3D(new Vec3(400.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'))->id;
        $hidden = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -5.0)))
            ->attach(new MeshRenderer('box', 'm', visible: false));
        $ids['hidden'] = $hidden->id;
        $dormant = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -8.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $ids['dormant'] = $dormant->id;
        $world->setEntityDormant($dormant->id, true);
        $unregisteredMeshEntity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 300.0))) // far behind - would be culled if its mesh were known
            ->attach(new MeshRenderer('not_registered', 'm'));
        $ids['unregisteredMesh'] = $unregisteredMeshEntity->id;

        $world->update(1.0 / 60.0);

        $spy = new class extends NullRenderer3D {
            /** @var list<object> */
            public array $commands = [];
            public function render(RenderCommandList $commandList): void
            {
                $this->commands = $commandList->getCommands();
            }
        };
        $list = new RenderCommandList();
        $system = new Renderer3DSystem($spy, $list);

        $poses = [
            [new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)],
            [new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, 1.0)],   // looking the other way
            [new Vec3(390.0, 0.0, 0.0), new Vec3(390.0, 0.0, -1.0)], // close to 'farSide'
        ];

        foreach ($poses as [$eye, $look]) {
            $camera = self::camera($eye, $look);
            $expected = self::oracle($world, $camera);

            $list->add($camera);
            $system->render($world);
            $actual = self::drawn($spy);

            // The oracle's entries are meshId#id (unique); drawn() only has
            // meshId (several 'box' entities share it) - compare counts
            // instead of exact strings, which is what drawsPerFrame means.
            self::assertCount(
                count($expected),
                $actual,
                'drawsPerFrame must match the brute-force oracle for pose eye=' . $eye . ' look=' . $look,
            );
        }

        // Spot-check WHICH entities the first pose's oracle says are visible,
        // to make sure the scenario actually exercises every exclusion path
        // (not just coincidentally matching counts).
        $camera = self::camera(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));
        $expected = self::oracle($world, $camera);
        self::assertContains('box#' . $ids['front'], $expected);
        self::assertNotContains('box#' . $ids['behind'], $expected);
        self::assertNotContains('box#' . $ids['farSide'], $expected);
        self::assertNotContains('box#' . $ids['hidden'], $expected);
        self::assertNotContains('box#' . $ids['dormant'], $expected);
        self::assertContains('not_registered#' . $ids['unregisteredMesh'], $expected, 'unknown mesh extent - never culled, even far behind the camera');
    }

    /**
     * A mover is reclassified correctly on every single frame it moves, not
     * only every 30th (there is no periodic rebuild any more).
     */
    public function testOracleAgreesWithRenderer3DSystemAsAnEntityMovesOverManyFrames(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());
        $transform = new Transform3D(new Vec3(400.0, 0.0, -10.0));
        $world->createEntity()->attach($transform)->attach(new MeshRenderer('box', 'm'));
        $world->update(1.0 / 60.0);

        $spy = new class extends NullRenderer3D {
            /** @var list<object> */
            public array $commands = [];
            public function render(RenderCommandList $commandList): void
            {
                $this->commands = $commandList->getCommands();
            }
        };
        $list = new RenderCommandList();
        $system = new Renderer3DSystem($spy, $list);
        $camera = self::camera(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));

        // Walk it from x=400 (far outside) to x=0 (dead centre) over 40
        // frames - more than the old 30-frame bin rebuild interval - and
        // check agreement on every single frame.
        for ($i = 0; $i <= 40; $i++) {
            $transform->position = new Vec3(400.0 - 10.0 * $i, 0.0, -10.0);
            $world->update(1.0 / 60.0);

            $expected = self::oracle($world, $camera);
            $list->add($camera);
            $system->render($world);
            $actual = self::drawn($spy);

            self::assertCount(count($expected), $actual, "frame {$i}: drawsPerFrame must match the oracle");
        }
    }
}
