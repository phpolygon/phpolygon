<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Visibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Transform3DSystem;
use PHPolygon\Visibility\VisibilityService;

final class VisibilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
    }

    private static function camera(Vec3 $eye = new Vec3(0.0, 0.0, 0.0)): array
    {
        return [
            Mat4::lookAt($eye, new Vec3($eye->x, $eye->y, $eye->z - 1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        ];
    }

    public function testForWorldResolvesTheSameInstancePerWorld(): void
    {
        $world = new World();
        $a = VisibilityService::forWorld($world);
        $b = VisibilityService::forWorld($world);
        self::assertSame($a, $b);

        $other = VisibilityService::forWorld(new World());
        self::assertNotSame($a, $other);
    }

    public function testWithoutACameraEveryVisibleEntityIsReturned(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -1000.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $service->beginFrame(null, null);

        self::assertSame([1], $service->visibleEntities());
    }

    public function testCameraCullsAnEntityOutsideTheFrustum(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $inView = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $behind = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        [$view, $proj] = self::camera();
        $service->beginFrame($view, $proj);

        self::assertSame([$inView->id], $service->visibleEntities());
        self::assertTrue($service->isVisible($inView->id));
        self::assertFalse($service->isVisible($behind->id));
    }

    public function testInvisibleMeshFlagRemovesTheEntryEvenWithoutACamera(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $service->beginFrame(null, null);
        self::assertSame([$entity->id], $service->visibleEntities());

        $entity->get(MeshRenderer::class)->visible = false;
        $service->beginFrame(null, null);
        self::assertSame([], $service->visibleEntities());

        $entity->get(MeshRenderer::class)->visible = true;
        $service->beginFrame(null, null);
        self::assertSame([$entity->id], $service->visibleEntities());
    }

    public function testDormantEntityIsExcludedEvenWhenItsCellIsVisible(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $world->setEntityDormant($entity->id, true);
        $service->beginFrame(null, null);

        self::assertSame([], $service->visibleEntities());
        self::assertFalse($service->isVisible($entity->id));
    }

    public function testUnregisteredMeshIsNeverCulled(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        // 'ghost' is never registered in MeshRegistry - unknown extent.
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0))) // behind the camera
            ->attach(new MeshRenderer('ghost', 'm'));

        [$view, $proj] = self::camera();
        $service->beginFrame($view, $proj);

        self::assertSame([$entity->id], $service->visibleEntities(), 'an entry with unknown mesh extent is never culled');
    }

    public function testMeshIdChangeRefreshesTheSphere(): void
    {
        MeshRegistry::register('small', BoxMesh::generate(0.1, 0.1, 0.1));
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(-200.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('small', 'm'));

        [$view, $proj] = self::camera();
        $service->beginFrame($view, $proj);
        self::assertSame([], $service->visibleEntities(), 'the tiny box at x=-200 misses the frustum');

        // A bigger mesh at the same position reaches into the frustum.
        MeshRegistry::register('huge', BoxMesh::generate(500.0, 500.0, 500.0));
        $entity->get(MeshRenderer::class)->meshId = 'huge';
        $service->beginFrame($view, $proj);
        self::assertSame([$entity->id], $service->visibleEntities(), 'the meshId hook must refresh the bounding sphere');
    }

    public function testMovedEntityIsReclassifiedTheSameFrameViaTransform3DSystem(): void
    {
        $world = new World();
        $transforms = new Transform3DSystem();
        $world->addSystem($transforms);
        $service = VisibilityService::forWorld($world);

        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(600.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));
        $world->update(1.0 / 60.0);

        [$view, $proj] = self::camera();
        $service->beginFrame($view, $proj);
        self::assertSame([], $service->visibleEntities(), 'far off to the side, outside the frustum');

        $entity->get(Transform3D::class)->position = new Vec3(0.0, 0.0, -10.0);
        $world->update(1.0 / 60.0);
        $service->beginFrame($view, $proj);
        self::assertSame([$entity->id], $service->visibleEntities(), 'moved into view the same tick it moved');
    }

    public function testFrameTestSphereAndSees(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);

        $service->beginFrame(null, null);
        self::assertNull($service->frame());
        self::assertTrue($service->testSphere(0.0, 0.0, 1000.0, 1.0), 'no camera - nothing is culled');
        self::assertTrue($service->sees(new Vec3(0.0, 0.0, 1000.0), 1.0));

        [$view, $proj] = self::camera();
        $service->beginFrame($view, $proj);
        self::assertNotNull($service->frame());
        self::assertTrue($service->testSphere(0.0, 0.0, -10.0, 1.0));
        self::assertFalse($service->testSphere(0.0, 0.0, 1000.0, 1.0));
        self::assertTrue($service->sees(new Vec3(0.0, 0.0, -10.0), 1.0, maxDistance: 20.0));
        self::assertFalse($service->sees(new Vec3(0.0, 0.0, -10.0), 1.0, maxDistance: 5.0), 'within the frustum but farther than maxDistance');
    }

    public function testTierAtStubFollowsCellVisibility(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, -10.0)))
            ->attach(new MeshRenderer('box', 'm'));

        $service->beginFrame(null, null);
        self::assertTrue($service->tierAt(0.0, -10.0), 'no camera - trivially in view');

        [$view, $proj] = self::camera();
        $service->beginFrame($view, $proj);
        self::assertTrue($service->tierAt(0.0, -10.0));
        self::assertFalse($service->tierAt(0.0, 1000.0));
    }

    public function testNoCullEnvironmentVariableDisablesClassification(): void
    {
        $world = new World();
        $service = VisibilityService::forWorld($world);
        $entity = $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 10.0))) // behind the camera
            ->attach(new MeshRenderer('box', 'm'));

        putenv('PHPOLYGON_NO_CULL=1');
        try {
            [$view, $proj] = self::camera();
            $service->beginFrame($view, $proj);
            self::assertSame([$entity->id], $service->visibleEntities());
        } finally {
            putenv('PHPOLYGON_NO_CULL');
        }
    }
}
