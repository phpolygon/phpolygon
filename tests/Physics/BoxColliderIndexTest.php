<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BoxColliderIndex;
use PHPolygon\System\Physics3DSystem;
use PHPolygon\System\Transform3DSystem;

/**
 * The persistent box-collider index must hand out what the old per-tick scan did: the same solid boxes, in
 * pool order, whatever happened to them since the last tick (moved, switched between trigger and solid,
 * attached, destroyed, parked and woken). Characters resolved against it must end up exactly where they
 * end up against every box (`broadphase: false`).
 */
final class BoxColliderIndexTest extends TestCase
{
    private function solidBox(World $world, Vec3 $position, Vec3 $size, bool $trigger = false, ?Quaternion $rotation = null): Entity
    {
        $entity = $world->createEntity();
        $entity->attach(new Transform3D($position, $rotation));
        $entity->attach(new BoxCollider3D(size: $size, isTrigger: $trigger));

        return $entity;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<int>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn(array $entry): int => (int) $entry['entityId'], $entries);
    }

    public function testQueryReturnsBoxesNearTheRectangleInPoolOrder(): void
    {
        $world = new World();
        $near = $this->solidBox($world, new Vec3(1.0, 0.5, 1.0), new Vec3(1.0, 1.0, 1.0));
        $far = $this->solidBox($world, new Vec3(500.0, 0.5, 500.0), new Vec3(1.0, 1.0, 1.0));
        $also = $this->solidBox($world, new Vec3(-2.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $slab = $this->solidBox($world, new Vec3(0.0, -0.5, 0.0), new Vec3(2000.0, 1.0, 2000.0));

        $index = new BoxColliderIndex();
        $index->sync($world);

        $found = self::ids($index->boxesIn(-5.0, -5.0, 5.0, 5.0));
        self::assertSame([$near->id, $also->id, $slab->id], $found, 'pool order, the slab is in every query');
        self::assertSame([$far->id, $slab->id], self::ids($index->boxesIn(499.0, 499.0, 501.0, 501.0)));
        self::assertSame(1, $index->stats()['large'], 'the slab is kept out of the grid');
    }

    public function testTurnedBoxesAreAKindOfTheirOwn(): void
    {
        $world = new World();
        $flat = $this->solidBox($world, new Vec3(0.0, 0.5, 0.0), new Vec3(2.0, 1.0, 2.0));
        $turned = $this->solidBox($world, new Vec3(1.0, 0.5, 1.0), new Vec3(2.0, 1.0, 2.0), rotation: Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.7));

        $index = new BoxColliderIndex();
        $index->sync($world);

        self::assertSame([$flat->id], self::ids($index->boxesIn(-3.0, -3.0, 3.0, 3.0)));
        self::assertSame([$turned->id], self::ids($index->rotatedIn(-3.0, -3.0, 3.0, 3.0)));
        self::assertTrue($index->hasRotated());
    }

    public function testFlippingTriggersCostsNoRefresh(): void
    {
        $world = new World();
        $flippable = [];
        for ($i = 0; $i < 40; $i++) {
            $rotation = $i % 4 === 0 ? Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.5) : null;
            $flippable[] = $this->solidBox($world, new Vec3($i * 3.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0), trigger: true, rotation: $rotation);
        }
        $index = new BoxColliderIndex();
        $index->sync($world);
        $before = $index->stats();

        self::assertSame([], $index->boxesIn(-10.0, -10.0, 200.0, 10.0), 'a trigger is no candidate');
        self::assertSame([], $index->rotatedIn(-10.0, -10.0, 200.0, 10.0));

        foreach ($flippable as $entity) {
            $entity->get(BoxCollider3D::class)->isTrigger = false;
        }
        $index->sync($world);

        self::assertCount(30, $index->boxesIn(-10.0, -10.0, 200.0, 10.0));
        self::assertCount(10, $index->rotatedIn(-10.0, -10.0, 200.0, 10.0));
        self::assertSame($before['refreshes'], $index->stats()['refreshes'], 'no cold path for the flip');
        self::assertSame($before['rebuilds'], $index->stats()['rebuilds']);
        foreach ($flippable as $entity) {
            $collider = $entity->get(BoxCollider3D::class);
            self::assertNotNull($collider->cachedWorldAabb, 'caches exist for triggers');
            if ($collider->cachedRotated) {
                self::assertNotNull($collider->cachedOriented, 'the solid form of a turned box exists for triggers too');
                self::assertNotNull($collider->worldBvh(), 'the ray queries get the triangles of a box that turned solid, built on demand');
            }
        }

        $flippable[0]->get(BoxCollider3D::class)->isTrigger = true;
        self::assertNotContains($flippable[0]->id, self::ids($index->boxesIn(-10.0, -10.0, 200.0, 10.0)));
    }

    public function testSteadyStateReadsNothing(): void
    {
        $world = new World();
        for ($i = 0; $i < 30; $i++) {
            $this->solidBox($world, new Vec3($i * 2.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        }
        $transforms = new Transform3DSystem();
        $index = new BoxColliderIndex();
        $transforms->update($world, 0.016);
        $index->sync($world);
        $before = $index->stats();

        for ($tick = 0; $tick < 5; $tick++) {
            $transforms->update($world, 0.016);
            $index->sync($world);
        }

        self::assertSame($before, $index->stats());
    }

    public function testAMovedColliderLeavesItsOldCellsAndEntersTheNewOnes(): void
    {
        $world = new World();
        $transforms = new Transform3DSystem();
        $box = $this->solidBox($world, new Vec3(0.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $transforms->update($world, 0.016);
        $index = new BoxColliderIndex();
        $index->sync($world);
        self::assertSame([$box->id], self::ids($index->boxesIn(-1.0, -1.0, 1.0, 1.0)));

        $transform = $box->get(Transform3D::class);
        $transform->position = new Vec3(100.0, 0.5, 100.0);
        $transforms->update($world, 0.016);
        $index->sync($world);

        self::assertSame([], self::ids($index->boxesIn(-1.0, -1.0, 1.0, 1.0)));
        self::assertSame([$box->id], self::ids($index->boxesIn(99.0, 99.0, 101.0, 101.0)));

        // An in-place write plus touch(), and a direct world-matrix assignment, are both seen.
        $transform->position->x = 200.0;
        $transform->touch();
        $transforms->update($world, 0.016);
        $index->sync($world);
        self::assertSame([$box->id], self::ids($index->boxesIn(199.0, 99.0, 201.0, 101.0)));

        $transform->position = new Vec3(-50.0, 0.5, 0.0);
        $transform->worldMatrix = $transform->getLocalMatrix();
        $index->sync($world);
        self::assertSame([$box->id], self::ids($index->boxesIn(-51.0, -1.0, -49.0, 1.0)));
    }

    public function testATurnedBoxChangesKindWhenItsRotationDoes(): void
    {
        $world = new World();
        $transforms = new Transform3DSystem();
        $box = $this->solidBox($world, new Vec3(0.0, 0.5, 0.0), new Vec3(2.0, 1.0, 1.0));
        $transforms->update($world, 0.016);
        $index = new BoxColliderIndex();
        $index->sync($world);
        self::assertCount(1, $index->boxesIn(-3.0, -3.0, 3.0, 3.0));
        self::assertFalse($index->hasRotated());

        $box->get(Transform3D::class)->rotation = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.6);
        $transforms->update($world, 0.016);
        $index->sync($world);

        self::assertSame([], $index->boxesIn(-3.0, -3.0, 3.0, 3.0));
        self::assertCount(1, $index->rotatedIn(-3.0, -3.0, 3.0, 3.0));
        self::assertTrue($index->hasRotated());
    }

    public function testAttachAndDestroyAreSeenAndAnAppendedColliderComesLast(): void
    {
        $world = new World();
        $a = $this->solidBox($world, new Vec3(0.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $b = $this->solidBox($world, new Vec3(1.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $index = new BoxColliderIndex();
        $index->sync($world);

        $world->destroyEntity($a->id);
        $c = $this->solidBox($world, new Vec3(2.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $index->sync($world);

        self::assertSame([$b->id, $c->id], self::ids($index->boxesIn(-5.0, -5.0, 5.0, 5.0)));

        // A collider attached before its transform joins when the transform arrives.
        $late = $world->createEntity();
        $late->attach(new BoxCollider3D(size: new Vec3(1.0, 1.0, 1.0)));
        $index->sync($world);
        self::assertNotContains($late->id, self::ids($index->boxesIn(-5.0, -5.0, 5.0, 5.0)));
        $late->attach(new Transform3D(new Vec3(3.0, 0.5, 0.0)));
        $index->sync($world);
        self::assertContains($late->id, self::ids($index->boxesIn(-5.0, -5.0, 5.0, 5.0)));
    }

    public function testDormantBoxesLeaveAndTheWokenOneComesLastLikeInThePool(): void
    {
        $world = new World();
        $a = $this->solidBox($world, new Vec3(0.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $b = $this->solidBox($world, new Vec3(1.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $c = $this->solidBox($world, new Vec3(2.0, 0.5, 0.0), new Vec3(1.0, 1.0, 1.0));
        $index = new BoxColliderIndex();
        $index->sync($world);

        $world->setEntityDormant($a->id, true);
        $index->sync($world);
        self::assertSame([$b->id, $c->id], self::ids($index->boxesIn(-5.0, -5.0, 5.0, 5.0)));

        $world->setEntityDormant($a->id, false);
        $index->sync($world);
        $pool = array_keys($world->componentPool(BoxCollider3D::class));
        self::assertSame($pool, self::ids($index->boxesIn(-5.0, -5.0, 5.0, 5.0)), 'the pool order puts the woken box last');
        self::assertSame($a->id, $pool[2]);
    }

    /** The grid answers like a scan: every box that overlaps a rectangle is in the answer, nothing is twice, nothing out of order. */
    public function testRandomRectanglesAgainstAScan(): void
    {
        mt_srand(11);
        $world = new World();
        for ($i = 0; $i < 400; $i++) {
            $size = new Vec3(0.3 + mt_rand(0, 900) / 100.0, 0.5, 0.3 + mt_rand(0, 900) / 100.0);
            $rotation = $i % 7 === 0 ? Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), mt_rand(10, 300) / 100.0) : null;
            $this->solidBox($world, new Vec3(mt_rand(-5000, 5000) / 100.0, 0.25, mt_rand(-5000, 5000) / 100.0), $size, trigger: $i % 5 === 0, rotation: $rotation);
        }
        $this->solidBox($world, new Vec3(0.0, -0.5, 0.0), new Vec3(400.0, 1.0, 400.0));

        $grid = new BoxColliderIndex();
        $all = new BoxColliderIndex(broadphase: false);
        $grid->sync($world);
        $all->sync($world);

        for ($q = 0; $q < 200; $q++) {
            $x = mt_rand(-6000, 6000) / 100.0;
            $z = mt_rand(-6000, 6000) / 100.0;
            $w = mt_rand(0, 800) / 100.0;
            $got = $grid->boxesIn($x - $w, $z - $w, $x + $w, $z + $w);
            $gotIds = self::ids($got);
            self::assertSame($gotIds, array_values(array_unique($gotIds)));
            $sorted = $gotIds;
            sort($sorted);
            self::assertSame($sorted, $gotIds, 'pool order');

            $expected = [];
            foreach ($all->boxesIn(0.0, 0.0, 0.0, 0.0) as $entry) {
                if ($entry['min']->x <= $x + $w && $entry['max']->x >= $x - $w && $entry['min']->z <= $z + $w && $entry['max']->z >= $z - $w) {
                    $expected[] = $entry['entityId'];
                }
            }
            self::assertSame($expected, $gotIds);

            $expectedTurned = [];
            foreach ($all->rotatedIn(0.0, 0.0, 0.0, 0.0) as $entry) {
                if ($entry['min']->x <= $x + $w && $entry['max']->x >= $x - $w && $entry['min']->z <= $z + $w && $entry['max']->z >= $z - $w) {
                    $expectedTurned[] = $entry['entityId'];
                }
            }
            self::assertSame($expectedTurned, self::ids($grid->rotatedIn($x - $w, $z - $w, $x + $w, $z + $w)));
        }
    }

    /**
     * Two identical worlds, one resolved with the grid and one against every box, driven by the same random
     * walk plus the changes a game makes (colliders flipping, moving, spawning, dying, parked and woken). The
     * characters must stay bit-identical. Thick boxes make some pushes longer than the query reach.
     */
    public function testCharactersEndExactlyWhereTheyEndAgainstEveryBox(): void
    {
        try {
            foreach ([3, 8, 21] as $seed) {
                $this->runEquivalence($seed);
            }
        } finally {
            putenv('PHPOLYGON_DEV_WARNINGS');
        }
    }

    /**
     * A push can be longer than the area the candidates were asked for: out of a thick box the capsule goes
     * the whole way, and the box it lands in comes after it in the pool. Both must be found.
     */
    public function testAPushLongerThanTheQueryReachStillFindsTheNextBox(): void
    {
        $results = [];
        foreach ([true, false] as $broadphase) {
            $world = new World();
            $transforms = new Transform3DSystem();
            $physics = new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), -500.0, $broadphase);
            $this->solidBox($world, new Vec3(0.0, 30.0, 0.0), new Vec3(14.0, 60.0, 14.0));
            $this->solidBox($world, new Vec3(8.0, 30.0, 0.0), new Vec3(2.0, 60.0, 4.0));
            $transform = new Transform3D(new Vec3(0.5, 30.0, 0.0));
            $controller = new CharacterController3D(height: 1.8, radius: 0.35);
            $world->createEntity()->attach($transform)->attach($controller);

            $transforms->update($world, 0.016);
            $physics->update($world, 0.016);
            $results[] = [$transform->position->x, $transform->position->y, $transform->position->z];
        }

        self::assertSame($results[1], $results[0]);
        self::assertGreaterThan(5.0, abs($results[1][0] - 0.5), 'the scenario pushes further than the query reach');
    }

    private function runEquivalence(int $seed): void
    {
        putenv('PHPOLYGON_DEV_WARNINGS=0');
        $worlds = [];
        foreach ([true, false] as $broadphase) {
            $worlds[] = [
                'world' => new World(),
                'transforms' => new Transform3DSystem(),
                'physics' => new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), -50.0, $broadphase),
                'colliders' => [],
                'ids' => [],
                'characters' => [],
            ];
        }

        // The same sequence of calls on both worlds: the ids and the pool order come out equal.
        foreach ($worlds as &$w) {
            mt_srand($seed);
            $this->populate($w);
        }
        unset($w);

        // Characters standing inside the thick boxes: pushes of several metres.
        foreach ($worlds as &$w) {
            [$transform] = $w['characters'][0];
            $first = $w['colliders'][0];
            $first->isTrigger = false;
            $centre = $w['world']->getComponent($w['ids'][0], Transform3D::class)->position;
            $transform->position = new Vec3($centre->x, 1.0, $centre->z);
        }
        unset($w);

        mt_srand($seed * 7919);
        $dt = 1.0 / 60.0;
        for ($tick = 0; $tick < 220; $tick++) {
            $ops = [];
            for ($k = 0; $k < 4; $k++) {
                $ops[] = [mt_rand(0, 99), mt_rand(0, 219), mt_rand(-300, 300), mt_rand(-300, 300), mt_rand(0, 9)];
            }
            $walk = [];
            foreach ($worlds[0]['characters'] as $i => $_) {
                $walk[$i] = [mt_rand(-100, 100) / 100.0, mt_rand(-100, 100) / 100.0, mt_rand(0, 99) < 4];
            }

            foreach ($worlds as &$w) {
                $world = $w['world'];
                foreach ($ops as [$what, $which, $dx, $dz, $extra]) {
                    $collider = $w['colliders'][$which];
                    if ($what < 14) {
                        $collider->isTrigger = !$collider->isTrigger;
                    } elseif ($what < 22) {
                        $entityId = $w['ids'][$which];
                        if (!$world->isAlive($entityId)) {
                            continue;
                        }
                        $transform = $world->getComponent($entityId, Transform3D::class);
                        $p = $transform->position;
                        $transform->position = new Vec3($p->x + $dx / 100.0, $p->y, $p->z + $dz / 100.0);
                    } elseif ($what < 26) {
                        $entity = $world->createEntity();
                        $entity->attach(new Transform3D(new Vec3($dx / 20.0, 0.4, $dz / 20.0)));
                        $newCollider = new BoxCollider3D(size: new Vec3(1.0 + $extra / 5.0, 0.8, 1.0));
                        $entity->attach($newCollider);
                        $w['colliders'][] = $newCollider;
                        $w['ids'][] = $entity->id;
                    } elseif ($what < 29) {
                        $entityId = $w['ids'][$which];
                        if ($world->isAlive($entityId)) {
                            $world->destroyEntity($entityId);
                        }
                    } elseif ($what < 33) {
                        $entityId = $w['ids'][$which];
                        if ($world->isAlive($entityId)) {
                            $world->setEntityDormant($entityId, !$world->isDormant($entityId));
                        }
                    }
                }
                foreach ($w['characters'] as $i => [$transform, $controller]) {
                    [$wx, $wz, $jump] = $walk[$i];
                    $p = $transform->position;
                    $transform->position = new Vec3($p->x + $wx * 0.06, $p->y, $p->z + $wz * 0.06);
                    if ($jump && $controller->isGrounded) {
                        $controller->velocity = new Vec3($controller->velocity->x, 7.0, $controller->velocity->z);
                    }
                }
                $w['transforms']->update($world, $dt);
                $w['physics']->update($world, $dt);
            }
            unset($w);

            foreach ($worlds[0]['characters'] as $i => [$t0, $c0]) {
                [$t1, $c1] = $worlds[1]['characters'][$i];
                $label = "seed $seed tick $tick character $i";
                self::assertSame($t1->position->x, $t0->position->x, "$label x");
                self::assertSame($t1->position->y, $t0->position->y, "$label y");
                self::assertSame($t1->position->z, $t0->position->z, "$label z");
                self::assertSame($c1->velocity->x, $c0->velocity->x, "$label vx");
                self::assertSame($c1->velocity->y, $c0->velocity->y, "$label vy");
                self::assertSame($c1->isGrounded, $c0->isGrounded, "$label grounded");
                self::assertSame($c1->groundEntityId, $c0->groundEntityId, "$label ground entity");
            }
        }
    }

    /**
     * @param array{world: World, colliders: list<BoxCollider3D>, ids: list<int>, characters: list<array{Transform3D, CharacterController3D}>} $w
     */
    private function populate(array &$w): void
    {
        $world = $w['world'];
        $floor = $world->createEntity();
        $floor->attach(new Transform3D(new Vec3(0.0, -0.5, 0.0)));
        $floor->attach(new BoxCollider3D(size: new Vec3(300.0, 1.0, 300.0)));
        for ($i = 0; $i < 220; $i++) {
            $size = $i % 15 === 0
                ? new Vec3(6.0 + mt_rand(0, 400) / 100.0, 3.0 + mt_rand(0, 300) / 100.0, 6.0 + mt_rand(0, 400) / 100.0)
                : new Vec3(0.4 + mt_rand(0, 400) / 100.0, 0.2 + mt_rand(0, 250) / 100.0, 0.4 + mt_rand(0, 400) / 100.0);
            $position = new Vec3(mt_rand(-3000, 3000) / 100.0, $size->y / 2.0 - 0.1, mt_rand(-3000, 3000) / 100.0);
            $rotation = mt_rand(0, 99) < 12 ? Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), mt_rand(5, 300) / 100.0) : Quaternion::identity();
            $entity = $world->createEntity();
            $entity->attach(new Transform3D($position, $rotation));
            $collider = new BoxCollider3D(size: $size, isTrigger: mt_rand(0, 99) < 15);
            $entity->attach($collider);
            $w['colliders'][] = $collider;
            $w['ids'][] = $entity->id;
        }
        for ($i = 0; $i < 6; $i++) {
            $entity = $world->createEntity();
            $transform = new Transform3D(new Vec3(mt_rand(-1500, 1500) / 100.0, mt_rand(100, 600) / 100.0, mt_rand(-1500, 1500) / 100.0));
            $controller = new CharacterController3D(height: 1.8, radius: 0.35, stepHeight: 0.5);
            $entity->attach($transform);
            $entity->attach($controller);
            $w['characters'][] = [$transform, $controller];
        }
    }
}
