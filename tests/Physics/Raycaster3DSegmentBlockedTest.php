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
use PHPolygon\Physics\BoxColliderIndex;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\CollisionMath;
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

    /** @return array{World, list<int>} a field of aligned, turned, trigger and oversized boxes, with the physics system's caches built */
    private function boxField(int $count, int $seed): array
    {
        mt_srand($seed);
        $world = new World();
        $world->addSystem(new Physics3DSystem(groundPlaneY: -1000.0));
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $e = $world->createEntity();
            $kind = mt_rand(0, 99);
            $rotation = $kind < 30 ? Quaternion::fromEuler(mt_rand(0, 100) / 200, mt_rand(0, 314) / 100, mt_rand(0, 100) / 200) : Quaternion::identity();
            $size = $kind >= 97 ? new Vec3(120.0, 0.5, 90.0) : new Vec3(mt_rand(5, 60) / 10, mt_rand(5, 40) / 10, mt_rand(5, 60) / 10);
            $e->attach(new Transform3D(new Vec3(mt_rand(-600, 600) / 10, mt_rand(0, 40) / 10, mt_rand(-600, 600) / 10), $rotation));
            $e->attach(new BoxCollider3D($size, isTrigger: $kind % 11 === 0));
            $ids[] = $e->id;
        }
        $world->update(1 / 60);

        return [$world, $ids];
    }

    /** @return list<array{Vec3, Vec3}> */
    private function segments(int $count, int $seed): array
    {
        mt_srand($seed);
        $out = [];
        for ($k = 0; $k < $count; $k++) {
            $from = new Vec3(mt_rand(-700, 700) / 10, mt_rand(-10, 60) / 10, mt_rand(-700, 700) / 10);
            $len = $k % 7 === 0 ? mt_rand(400, 1500) / 10 : mt_rand(0, 120) / 10;
            $angle = mt_rand(0, 6283) / 1000;
            $to = match ($k % 5) {
                0 => new Vec3($from->x, $from->y + $len, $from->z),                  // straight up
                1 => $from,                                                          // a point
                2 => new Vec3($from->x + $len, $from->y, $from->z),                  // along x
                default => new Vec3($from->x + cos($angle) * $len, $from->y + mt_rand(-30, 30) / 10, $from->z + sin($angle) * $len),
            };
            $out[] = [$from, $to];
        }

        return $out;
    }

    public function testIndexedAnswersEqualTheFullScanOnRandomFields(): void
    {
        $brute = new BoxColliderIndex(broadphase: false);   // not usable by segmentBlocked: it walks the pool
        $blocked = 0;
        $total = 0;
        foreach ([[300, 1], [800, 2], [50, 3]] as [$count, $seed]) {
            [$world] = $this->boxField($count, $seed);
            self::assertInstanceOf(BoxColliderIndex::class, BoxColliderIndex::forWorld($world), 'the physics system registered its index');
            foreach ($this->segments(400, $seed + 50) as [$from, $to]) {
                $expected = $this->raycaster->segmentBlocked($world, $from, $to, $brute);
                $actual = $this->raycaster->segmentBlocked($world, $from, $to);
                self::assertSame($expected, $actual, sprintf('field %d, segment (%.2f %.2f %.2f) -> (%.2f %.2f %.2f)', $count, $from->x, $from->y, $from->z, $to->x, $to->y, $to->z));
                $blocked += $actual ? 1 : 0;
                $total++;
            }
        }
        self::assertGreaterThan($total * 0.1, $blocked, 'enough segments hit something for the comparison to mean anything');
        self::assertLessThan($total * 0.95, $blocked, 'and enough pass for it to mean something too');
    }

    public function testSegmentCandidatesContainEveryBoxTheSegmentCrosses(): void
    {
        [$world, $ids] = $this->boxField(500, 7);
        $index = BoxColliderIndex::forWorld($world);
        self::assertNotNull($index);
        foreach ($this->segments(300, 8) as [$from, $to]) {
            $found = [];
            foreach ($index->segmentCandidates($from, $to) as $entry) {
                $found[$entry['entityId']] = true;
            }
            foreach ($ids as $id) {
                $box = $world->getComponent($id, BoxCollider3D::class);
                $aabb = $box->cachedWorldAabb;
                if ($box->isTrigger || $aabb === null) {
                    continue;
                }
                if (CollisionMath::segmentIntersectsAabb($from, $to, $aabb['min'], $aabb['max'])) {
                    self::assertArrayHasKey($id, $found, 'a box the segment passes through is a candidate');
                }
            }
        }
    }

    public function testDormancyBetweenTicksFallsBackToTheScan(): void
    {
        [$world, $ids] = $this->boxField(200, 4);
        $brute = new BoxColliderIndex(broadphase: false);
        $index = BoxColliderIndex::forWorld($world);
        self::assertNotNull($index);
        self::assertTrue($index->isCurrentFor($world));

        foreach (array_slice($ids, 0, 60) as $id) {
            $world->setEntityDormant($id, true);
        }
        self::assertFalse($index->isCurrentFor($world), 'the index is behind until the next tick');
        foreach ($this->segments(200, 9) as [$from, $to]) {
            self::assertSame(
                $this->raycaster->segmentBlocked($world, $from, $to, $brute),
                $this->raycaster->segmentBlocked($world, $from, $to),
            );
        }
        $world->update(1 / 60);
        self::assertTrue($index->isCurrentFor($world));
        foreach ($this->segments(200, 10) as [$from, $to]) {
            self::assertSame(
                $this->raycaster->segmentBlocked($world, $from, $to, $brute),
                $this->raycaster->segmentBlocked($world, $from, $to),
            );
        }
    }

    public function testNoIndexMeansTheOldScan(): void
    {
        $world = new World();
        $e = $world->createEntity();
        $e->attach(new Transform3D(new Vec3(0, 1.5, -2)));
        $box = new BoxCollider3D(new Vec3(2, 2, 1));
        $e->attach($box);
        $box = $e->get(BoxCollider3D::class);
        $box->cachedWorldAabb = $box->getWorldAABB($e->get(Transform3D::class)->getWorldMatrix());

        self::assertNull(BoxColliderIndex::forWorld($world));
        self::assertTrue($this->raycaster->segmentBlocked($world, new Vec3(0, 1.5, 0), new Vec3(0, 1.5, -4)));
        self::assertTrue($this->raycaster->segmentBlocked($world, new Vec3(0, 1.5, 0), new Vec3(0, 1.5, -4), new BoxColliderIndex()), 'an index that never saw the world is not consulted');
    }

    public function testMovedTurnedBoxDropsItsBvhAndBlocksAtTheNewPlace(): void
    {
        $world = new World();
        $world->addSystem(new Physics3DSystem(groundPlaneY: -1000.0));
        $box = $world->createEntity();
        $box->attach(new Transform3D(new Vec3(0, 1.5, -5), Quaternion::fromAxisAngle(new Vec3(0, 1, 0), M_PI / 4)));
        $box->attach(new BoxCollider3D(new Vec3(3, 3, 1)));
        $world->update(1 / 60);

        $eye = new Vec3(0, 1.5, 0);
        self::assertTrue($this->raycaster->segmentBlocked($world, $eye, new Vec3(0, 1.5, -10)));
        $collider = $box->get(BoxCollider3D::class);
        self::assertNotNull($collider->bvh);

        // Move it 10 m to the side (a door, a platform): the stale tree must not answer for the old place.
        $transform = $box->get(Transform3D::class);
        $transform->position = new Vec3(10, 1.5, -5);
        $transform->worldMatrix = $transform->getLocalMatrix();
        $world->update(1 / 60);

        self::assertNull($collider->bvh, 'a moved box drops its tree');
        self::assertFalse($this->raycaster->segmentBlocked($world, $eye, new Vec3(0, 1.5, -10)), 'old place is free');
        self::assertTrue($this->raycaster->segmentBlocked($world, new Vec3(10, 1.5, 0), new Vec3(10, 1.5, -10)), 'new place blocks');
        self::assertNotNull($collider->bvh, 'rebuilt on that query');
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
        self::assertNull($rotatedBox->bvh, 'the 12-triangle BVH of a turned box is built by the first query, not by the tick');
        self::assertNotNull($wallMesh->bvh);

        $aabbBefore = $alignedBox->cachedWorldAabb;
        $wallBvhBefore = $wallMesh->bvh;

        $eye = new Vec3(0, 1.5, 0);
        self::assertTrue($this->raycaster->segmentBlocked($world, $eye, new Vec3(10, 1.5, 0)), 'aligned box');
        self::assertTrue($this->raycaster->segmentBlocked($world, $eye, new Vec3(-10, 1.5, 0)), 'rotated box');
        $rotatedBvhBefore = $rotatedBox->bvh;
        self::assertNotNull($rotatedBvhBefore, 'built on first use');
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
