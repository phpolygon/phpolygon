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
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
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

    public function testRenderer3DSystemSortsTransparentDrawsBackToFrontAfterOpaque(): void
    {
        MaterialRegistry::register('opaque_test', new Material(albedo: new Color(1, 0, 0), alpha: 1.0));
        MaterialRegistry::register('glass_test',  new Material(albedo: new Color(0, 0, 1), alpha: 0.5));

        $world = new World();
        $renderer = new NullRenderer3D();
        $commandList = new RenderCommandList();

        // Camera at the origin so distance-from-camera = |position|.
        $camera = $world->createEntity();
        $camera->attach(new Camera3DComponent(active: true));
        $camera->attach(new Transform3D(new Vec3(0, 0, 0)));

        // Two opaque entities (any order).
        $opaque1 = $world->createEntity();
        $opaque1->attach(new MeshRenderer('m1', 'opaque_test'));
        $opaque1->attach(new Transform3D(new Vec3(0, 0, 5)));
        $opaque2 = $world->createEntity();
        $opaque2->attach(new MeshRenderer('m2', 'opaque_test'));
        $opaque2->attach(new Transform3D(new Vec3(0, 0, 50)));

        // Three transparent entities at increasing distance (5, 25, 100).
        $glassNear = $world->createEntity();
        $glassNear->attach(new MeshRenderer('near',  'glass_test'));
        $glassNear->attach(new Transform3D(new Vec3(0, 0, 5)));
        $glassMid  = $world->createEntity();
        $glassMid->attach(new MeshRenderer('mid',   'glass_test'));
        $glassMid->attach(new Transform3D(new Vec3(0, 0, 25)));
        $glassFar  = $world->createEntity();
        $glassFar->attach(new MeshRenderer('far',   'glass_test'));
        $glassFar->attach(new Transform3D(new Vec3(0, 0, 100)));

        $world->addSystem(new Camera3DSystem($commandList, 1280, 720));
        $world->addSystem(new Renderer3DSystem($renderer, $commandList));
        $world->render();

        $lastList = $renderer->getLastCommandList();
        $this->assertNotNull($lastList);
        $draws = $lastList->ofType(DrawMesh::class);

        $meshIds = array_map(static fn(DrawMesh $d): string => $d->meshId, $draws);

        // The opaque set comes first (order within is irrelevant), and the
        // transparent set follows in back-to-front order: far -> mid -> near.
        $this->assertSame(['m1', 'm2'], array_slice($meshIds, 0, 2));
        $this->assertSame(['far', 'mid', 'near'], array_slice($meshIds, 2));
    }

    public function testTransparentSortUsesMeshSphereCentreForOffPivotGeometry(): void
    {
        // Two transparent meshes, both with their entity at the origin and
        // the camera at z = 100 looking down -z. If the sort fell back to
        // matrix.translation both would land at distance 100. With sphere-
        // centre awareness, mesh 'far_pivot' (whose vertices live at z = -50
        // in local space) is at world z = -50 -> distance 150, while
        // 'near_pivot' (vertices at z = +50) is at world z = +50 ->
        // distance 50. Back-to-front order must be far -> near.
        MaterialRegistry::register('glass_pivot', new Material(albedo: new Color(0, 1, 0), alpha: 0.5));

        // Build a tiny tri whose vertices are clustered around z = -50 /
        // z = +50 so the bounding-sphere centre lands at the cluster, not
        // at the entity origin.
        $verts = static fn(float $z): MeshData => new MeshData(
            vertices: [-1.0, 0.0, $z,  1.0, 0.0, $z,  0.0, 1.0, $z],
            normals:  [0.0, 0.0, 1.0,  0.0, 0.0, 1.0,  0.0, 0.0, 1.0],
            uvs:      [0.0, 0.0,  1.0, 0.0,  0.5, 1.0],
            indices:  [0, 1, 2],
        );
        MeshRegistry::register('far_pivot',  $verts(-50.0));
        MeshRegistry::register('near_pivot', $verts(50.0));

        $world = new World();
        $renderer = new NullRenderer3D();
        $commandList = new RenderCommandList();

        // Camera at (0, 0, 100) looking towards -z.
        $camera = $world->createEntity();
        $camera->attach(new Camera3DComponent(active: true));
        $camera->attach(new Transform3D(new Vec3(0, 0, 100)));

        // Both transparent entities pivoted at the origin.
        $a = $world->createEntity();
        $a->attach(new MeshRenderer('far_pivot',  'glass_pivot'));
        $a->attach(new Transform3D(new Vec3(0, 0, 0)));

        $b = $world->createEntity();
        $b->attach(new MeshRenderer('near_pivot', 'glass_pivot'));
        $b->attach(new Transform3D(new Vec3(0, 0, 0)));

        $world->addSystem(new Camera3DSystem($commandList, 1280, 720));
        $world->addSystem(new Renderer3DSystem($renderer, $commandList));
        $world->render();

        $lastList = $renderer->getLastCommandList();
        $this->assertNotNull($lastList);
        $draws = $lastList->ofType(DrawMesh::class);
        $meshIds = array_map(static fn(DrawMesh $d): string => $d->meshId, $draws);

        // Far first (z = -50, distance from camera at z=100 is 150),
        // near second (z = +50, distance 50). Translation-only sort would
        // produce them in arbitrary order because both pivots are at z = 0.
        $this->assertSame(['far_pivot', 'near_pivot'], $meshIds);
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
