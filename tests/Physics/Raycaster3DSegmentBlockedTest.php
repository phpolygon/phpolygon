<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\Raycaster3D;
use PHPolygon\Physics\Triangle;
use PHPolygon\System\Physics3DSystem;

/**
 * Segment occlusion query against the collision data the physics system caches
 * on the colliders.
 */
class Raycaster3DSegmentBlockedTest extends TestCase
{
    private Raycaster3D $raycaster;

    protected function setUp(): void
    {
        $this->raycaster = new Raycaster3D();
    }

    /** A box whose world AABB is already cached, as Physics3DSystem leaves it. */
    private static function cachedBox(World $world, Vec3 $min, Vec3 $max, bool $isTrigger = false): int
    {
        $box = new BoxCollider3D(isTrigger: $isTrigger);
        $box->cachedWorldAabb = ['min' => $min, 'max' => $max];

        return $world->createEntity()->attach($box)->id;
    }

    public function testEmptyWorldIsClear(): void
    {
        self::assertFalse($this->raycaster->segmentBlocked(new World(), new Vec3(0, 1, 0), new Vec3(0, 1, -10)));
    }

    public function testSolidBoxBlocksTriggerDoesNot(): void
    {
        $world = new World();
        $from = new Vec3(0.0, 1.5, 0.0);
        $to = new Vec3(0.0, 1.5, -4.0);

        self::cachedBox($world, new Vec3(-1, 0, -2.5), new Vec3(1, 3, -1.5), isTrigger: true);
        self::assertFalse($this->raycaster->segmentBlocked($world, $from, $to), 'trigger in the way');

        $id = self::cachedBox($world, new Vec3(-1, 0, -2.5), new Vec3(1, 3, -1.5));
        self::assertTrue($this->raycaster->segmentBlocked($world, $from, $to));
        self::assertFalse($this->raycaster->segmentBlocked($world, $from, new Vec3(0, 1.5, -1.0)), 'ends before the box');
        self::assertFalse($this->raycaster->segmentBlocked($world, new Vec3(0, 4, 0), new Vec3(0, 4, -4)), 'passes above');

        $world->destroyEntity($id);
        self::assertFalse($this->raycaster->segmentBlocked($world, $from, $to));
    }

    public function testBoxContainingTheStartDoesNotBlock(): void
    {
        $world = new World();
        self::cachedBox($world, new Vec3(-10, -1, -10), new Vec3(10, 5, 10));

        self::assertFalse($this->raycaster->segmentBlocked($world, new Vec3(0, 1.5, 0), new Vec3(2, 1.5, -2)));
        // Looking INTO the same box from outside is blocked.
        self::assertTrue($this->raycaster->segmentBlocked($world, new Vec3(0, 1.5, 20), new Vec3(0, 1.5, 0)));
    }

    public function testMeshColliderBvhBlocksTriggerMeshDoesNot(): void
    {
        $world = new World();
        $from = new Vec3(0.0, 1.5, 0.0);
        $to = new Vec3(0.0, 1.5, -4.0);
        $wall = [new Triangle(new Vec3(-2, 0, -3), new Vec3(2, 0, -3), new Vec3(0, 4, -3))];

        $trigger = new MeshCollider3D('segment_test_wall', isTrigger: true);
        $trigger->bvh = BVH::build($wall);
        $world->createEntity()->attach($trigger);
        self::assertFalse($this->raycaster->segmentBlocked($world, $from, $to));

        $mesh = new MeshCollider3D('segment_test_wall');
        $mesh->bvh = BVH::build($wall);
        $world->createEntity()->attach($mesh);
        self::assertTrue($this->raycaster->segmentBlocked($world, $from, $to));
        self::assertTrue($this->raycaster->segmentBlocked($world, $to, $from), 'double-sided');
        self::assertFalse($this->raycaster->segmentBlocked($world, $from, new Vec3(0, 1.5, -2.5)), 'ends before the wall');
        self::assertFalse($this->raycaster->segmentBlocked($world, new Vec3(5, 1.5, 0), new Vec3(5, 1.5, -4)), 'beside the wall');
    }

    public function testColliderWithoutCachedDataDoesNotBlock(): void
    {
        // The query never builds collision data itself: a collider the physics
        // system has not processed yet is invisible to it.
        $world = new World();
        $e = $world->createEntity();
        $e->attach(new Transform3D(new Vec3(0, 1.5, -2)));
        $e->attach(new BoxCollider3D(new Vec3(2, 2, 1)));

        self::assertFalse($this->raycaster->segmentBlocked($world, new Vec3(0, 1.5, 0), new Vec3(0, 1.5, -4)));
    }

    public function testReadsWhatPhysicsCachedAndRebuildsNothing(): void
    {
        $meshId = 'segment_test_mesh_' . bin2hex(random_bytes(4));
        MeshRegistry::register($meshId, BoxMesh::generate(4.0, 4.0, 0.5));

        $world = new World();
        $world->addSystem(new Physics3DSystem(groundPlaneY: -1000.0));

        // Axis-aligned box across +X, rotated box across -X, mesh wall across -Z.
        $aligned = $world->createEntity();
        $aligned->attach(new Transform3D(new Vec3(5, 1.5, 0)));
        $aligned->attach(new BoxCollider3D(new Vec3(1, 3, 3)));

        $rotated = $world->createEntity();
        $rotated->attach(new Transform3D(new Vec3(-5, 1.5, 0), Quaternion::fromAxisAngle(new Vec3(0, 1, 0), M_PI / 4)));
        $rotated->attach(new BoxCollider3D(new Vec3(1, 3, 3)));

        $wall = $world->createEntity();
        $wall->attach(new Transform3D(new Vec3(0, 1.5, -5)));
        $wall->attach(new MeshCollider3D($meshId));

        $world->update(1 / 60);

        $alignedBox = $aligned->get(BoxCollider3D::class);
        $rotatedBox = $rotated->get(BoxCollider3D::class);
        $wallMesh = $wall->get(MeshCollider3D::class);
        self::assertNotNull($alignedBox->cachedWorldAabb);
        self::assertFalse($alignedBox->cachedRotated);
        self::assertTrue($rotatedBox->cachedRotated);
        self::assertNotNull($rotatedBox->bvh);
        self::assertNotNull($wallMesh->bvh);

        $aabbBefore = $alignedBox->cachedWorldAabb;
        $rotatedBvhBefore = $rotatedBox->bvh;
        $wallBvhBefore = $wallMesh->bvh;

        $eye = new Vec3(0, 1.5, 0);
        self::assertTrue($this->raycaster->segmentBlocked($world, $eye, new Vec3(10, 1.5, 0)), 'aligned box');
        self::assertTrue($this->raycaster->segmentBlocked($world, $eye, new Vec3(-10, 1.5, 0)), 'rotated box');
        self::assertTrue($this->raycaster->segmentBlocked($world, $eye, new Vec3(0, 1.5, -10)), 'mesh wall');
        self::assertFalse($this->raycaster->segmentBlocked($world, $eye, new Vec3(0, 1.5, 10)), 'open side');
        // Inside the rotated box's AABB corner but outside the box itself: the
        // triangle test, not the bound, decides.
        self::assertFalse($this->raycaster->segmentBlocked($world, new Vec3(-3.7, 1.5, 10), new Vec3(-3.7, 1.5, 1.3)));

        self::assertSame($aabbBefore, $alignedBox->cachedWorldAabb);
        self::assertSame($rotatedBvhBefore, $rotatedBox->bvh);
        self::assertSame($wallBvhBefore, $wallMesh->bvh);
    }
}
