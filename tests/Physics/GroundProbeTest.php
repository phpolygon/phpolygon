<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\GroundProbe;
use PHPolygon\System\Physics3DSystem;
use PHPUnit\Framework\TestCase;

final class GroundProbeTest extends TestCase
{
    private const float DT = 1.0 / 60.0;

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        parent::tearDown();
    }

    public function testAMeshFloorReportsItsColliderMesh(): void
    {
        [$world, $controller] = $this->scene();
        $floor = $this->meshFloor($world);
        $floor->attach(new MeshRenderer('visual_mesh', 'wet_stone'));
        $this->settle($world);

        $hit = GroundProbe::under($world, $controller);

        $this->assertNotNull($hit);
        $this->assertSame($floor->id, $hit->entityId);
        $this->assertSame('probe_floor', $hit->meshId, 'the collider mesh, not the visual one');
        $this->assertSame('wet_stone', $hit->materialId);
    }

    public function testABoxFloorReportsItsRenderer(): void
    {
        [$world, $controller] = $this->scene();
        $floor = $this->boxFloor($world);
        $floor->attach(new MeshRenderer('deck_mesh', 'planks'));
        $this->settle($world);

        $hit = GroundProbe::under($world, $controller);

        $this->assertNotNull($hit);
        $this->assertSame($floor->id, $hit->entityId);
        $this->assertSame('deck_mesh', $hit->meshId);
        $this->assertSame('planks', $hit->materialId);
    }

    public function testAnInvisibleBoxIsGroundWithoutIds(): void
    {
        [$world, $controller] = $this->scene();
        $floor = $this->boxFloor($world);
        $this->settle($world);

        $hit = GroundProbe::under($world, $controller);

        $this->assertNotNull($hit);
        $this->assertSame($floor->id, $hit->entityId);
        $this->assertNull($hit->meshId);
        $this->assertNull($hit->materialId);
    }

    public function testAHeightmapIsGroundWithoutIds(): void
    {
        $world = new World();
        $controller = new CharacterController3D();
        $terrain = $world->createEntity();
        $terrain->attach(new HeightmapCollider3D());
        $controller->groundEntityId = $terrain->id;

        $hit = GroundProbe::under($world, $controller);

        $this->assertNotNull($hit);
        $this->assertSame($terrain->id, $hit->entityId);
    }

    public function testInTheAirThereIsNothing(): void
    {
        [$world, $controller] = $this->scene();
        $world->update(self::DT);

        $this->assertFalse($controller->isGrounded);
        $this->assertNull(GroundProbe::under($world, $controller));
    }

    public function testAFloorThatBecameATriggerSinceTheTickIsNotReported(): void
    {
        [$world, $controller] = $this->scene();
        $mesh = $this->meshFloor($world);
        $this->settle($world);
        $this->assertNotNull(GroundProbe::under($world, $controller));

        $mesh->get(MeshCollider3D::class)->isTrigger = true;

        $this->assertNotNull($controller->groundEntityId, 'still the id of the last tick');
        $this->assertNull(GroundProbe::under($world, $controller));
    }

    public function testABoxThatBecameATriggerSinceTheTickIsNotReported(): void
    {
        [$world, $controller] = $this->scene();
        $box = $this->boxFloor($world);
        $this->settle($world);

        $box->get(BoxCollider3D::class)->isTrigger = true;

        $this->assertNull(GroundProbe::under($world, $controller));
    }

    public function testAFloorDestroyedSinceTheTickIsNotReported(): void
    {
        [$world, $controller] = $this->scene();
        $floor = $this->boxFloor($world);
        $this->settle($world);

        $world->destroyEntity($floor->id);

        $this->assertNull(GroundProbe::under($world, $controller));
    }

    public function testASolidBoxBesideATriggerMeshStillCounts(): void
    {
        [$world, $controller] = $this->scene();
        $floor = $this->boxFloor($world);
        MeshRegistry::register('probe_sensor', PlaneMesh::generate(4.0, 4.0));
        $floor->attach(new MeshCollider3D(meshId: 'probe_sensor', isTrigger: true));
        $floor->attach(new MeshRenderer('deck_mesh', 'planks'));
        $this->settle($world);

        $hit = GroundProbe::under($world, $controller);

        $this->assertNotNull($hit);
        $this->assertSame('deck_mesh', $hit->meshId, 'a trigger mesh names nothing');
    }

    /** @return array{World, CharacterController3D} */
    private function scene(): array
    {
        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -125.0));
        $player = $world->createEntity();
        $player->attach(new Transform3D(position: new Vec3(0.0, 2.0, 0.0)));
        $controller = new CharacterController3D(height: 1.8, radius: 0.3);
        $player->attach($controller);

        return [$world, $controller];
    }

    private function settle(World $world): void
    {
        for ($i = 0; $i < 60; $i++) {
            $world->update(self::DT);
        }
    }

    private function boxFloor(World $world): Entity
    {
        $floor = $world->createEntity();
        $floor->attach(new Transform3D(position: new Vec3(0.0, -0.5, 0.0)));
        $floor->attach(new BoxCollider3D(size: new Vec3(20.0, 1.0, 20.0), isStatic: true));

        return $floor;
    }

    private function meshFloor(World $world): Entity
    {
        MeshRegistry::register('probe_floor', PlaneMesh::generate(20.0, 20.0));
        $floor = $world->createEntity();
        $floor->attach(new Transform3D());
        $floor->attach(new MeshCollider3D(meshId: 'probe_floor'));

        return $floor;
    }
}
