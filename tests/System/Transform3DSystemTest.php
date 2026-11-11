<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Transform3DSystem;

class Transform3DSystemTest extends TestCase
{
    public function testInPlaceVectorWritesAreDetected(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $entity = $world->createEntity();
        $t = new Transform3D(new Vec3(1.0, 2.0, 3.0));
        $entity->attach($t);
        $world->update(0.016);

        // Game code writes into the vector object itself instead of assigning a
        // new one (Vec3 is mutable) - the dirty check must still see it.
        $t->position->y = 9.0;
        $world->update(0.016);
        $this->assertEqualsWithDelta(9.0, $t->getWorldPosition()->y, 1e-5);

        $t->scale->x = 2.0;
        $world->update(0.016);
        $this->assertEqualsWithDelta(3.0, $t->getWorldMatrix()->transformPoint(new Vec3(1.0, 0.0, 0.0))->x, 1e-5);

        // An untouched entity keeps the very same world matrix object.
        $before = $t->worldMatrix;
        $world->update(0.016);
        $this->assertSame($before, $t->worldMatrix);
    }

    public function testRootEntityWorldMatrixEqualsLocal(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(new Vec3(5.0, 3.0, 0.0)));

        $world->update(0.016);

        $t = $entity->get(Transform3D::class);
        $wt = $t->getWorldPosition();
        $this->assertEqualsWithDelta(5.0, $wt->x, 1e-5);
        $this->assertEqualsWithDelta(3.0, $wt->y, 1e-5);
    }

    public function testChildWorldMatrixIncludesParent(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $parent = $world->createEntity();
        $parentT = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $parent->attach($parentT);

        $child = $world->createEntity();
        $childT = new Transform3D(new Vec3(5.0, 0.0, 0.0));
        $child->attach($childT);

        // Set up hierarchy
        $parentT->addChild($childT, $child->id, $parent->id);

        $world->update(0.016);

        // Child world position should be parent(10) + child(5) = 15
        $wp = $childT->getWorldPosition();
        $this->assertEqualsWithDelta(15.0, $wp->x, 1e-5);
    }

    public function testDeepHierarchy(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $a = $world->createEntity();
        $at = new Transform3D(new Vec3(1.0, 0.0, 0.0));
        $a->attach($at);

        $b = $world->createEntity();
        $bt = new Transform3D(new Vec3(2.0, 0.0, 0.0));
        $b->attach($bt);
        $at->addChild($bt, $b->id, $a->id);

        $c = $world->createEntity();
        $ct = new Transform3D(new Vec3(3.0, 0.0, 0.0));
        $c->attach($ct);
        $bt->addChild($ct, $c->id, $b->id);

        $world->update(0.016);

        // a=1, b=1+2=3, c=1+2+3=6
        $this->assertEqualsWithDelta(1.0, $at->getWorldPosition()->x, 1e-5);
        $this->assertEqualsWithDelta(3.0, $bt->getWorldPosition()->x, 1e-5);
        $this->assertEqualsWithDelta(6.0, $ct->getWorldPosition()->x, 1e-5);
    }

    public function testRotatedParentAffectsChildPosition(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        // Parent rotated 90° around Y
        $parent = $world->createEntity();
        $parentT = new Transform3D(
            Vec3::zero(),
            Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), M_PI / 2),
        );
        $parent->attach($parentT);

        // Child at (5, 0, 0) in local space
        $child = $world->createEntity();
        $childT = new Transform3D(new Vec3(5.0, 0.0, 0.0));
        $child->attach($childT);

        $parentT->addChild($childT, $child->id, $parent->id);

        $world->update(0.016);

        // After parent 90° Y rotation, child's (5,0,0) becomes (0,0,-5) in world
        $wp = $childT->getWorldPosition();
        $this->assertEqualsWithDelta(0.0, $wp->x, 1e-4);
        $this->assertEqualsWithDelta(0.0, $wp->y, 1e-4);
        $this->assertEqualsWithDelta(-5.0, $wp->z, 1e-4);
    }

    public function testWorldClearResetsDirtyCacheSoChildrenRebuildCorrectly(): void
    {
        // After World::clear() the entity-id counter restarts from 0. If
        // Transform3DSystem's per-id snapshot survived, a freshly-built child
        // with floats matching the snapshot of the previous occupant of that
        // id would slip past isDirty as "unchanged" — its worldMatrix would
        // stay at the constructor default (=localMatrix) and the child would
        // render at LOCAL coordinates instead of parent*local. The
        // onWorldClear hook on AbstractSystem must drop that snapshot.

        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $parent = $world->createEntity();
        $parentT = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $parent->attach($parentT);

        $child = $world->createEntity();
        $childT = new Transform3D(new Vec3(2.0, 0.0, 0.0));
        $child->attach($childT);
        $parentT->addChild($childT, $child->id, $parent->id);

        $world->update(0.016);
        $this->assertEqualsWithDelta(12.0, $childT->getWorldPosition()->x, 1e-5);

        // Rebuild with the SAME ids and SAME floats — the snapshot would match
        // exactly without the reset, so isDirty would falsely return false.
        $world->clear();

        $parent2 = $world->createEntity();
        $parent2T = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $parent2->attach($parent2T);

        $child2 = $world->createEntity();
        $child2T = new Transform3D(new Vec3(2.0, 0.0, 0.0));
        $child2->attach($child2T);
        $parent2T->addChild($child2T, $child2->id, $parent2->id);

        $world->update(0.016);

        $this->assertEqualsWithDelta(12.0, $child2T->getWorldPosition()->x, 1e-5,
            'world->clear() must drop the snapshot so children recompute');
    }

    public function testRemoveChildStopsInheritance(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $parent = $world->createEntity();
        $parentT = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $parent->attach($parentT);

        $child = $world->createEntity();
        $childT = new Transform3D(new Vec3(5.0, 0.0, 0.0));
        $child->attach($childT);

        $parentT->addChild($childT, $child->id, $parent->id);
        $world->update(0.016);
        $this->assertEqualsWithDelta(15.0, $childT->getWorldPosition()->x, 1e-5);

        // Detach child
        $parentT->removeChild($childT, $child->id);
        $world->update(0.016);

        // Now child is root — world = local = 5
        $this->assertEqualsWithDelta(5.0, $childT->getWorldPosition()->x, 1e-5);
    }

    /**
     * A dormant subtree under an awake parent is skipped like a dormant root:
     * it is not recomputed while it sleeps, and catches up on its first awake
     * tick if its parent moved meanwhile - grandchildren included.
     */
    public function testDormantSubtreeIsSkippedAndCatchesUpOnWake(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $parent = $world->createEntity();
        $parentT = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $parent->attach($parentT);
        $child = $world->createEntity();
        $childT = new Transform3D(new Vec3(5.0, 0.0, 0.0));
        $child->attach($childT);
        $parentT->addChild($childT, $child->id, $parent->id);
        $grandchild = $world->createEntity();
        $grandT = new Transform3D(new Vec3(1.0, 0.0, 0.0));
        $grandchild->attach($grandT);
        $childT->addChild($grandT, $grandchild->id, $child->id);
        $world->update(0.016);
        $this->assertEqualsWithDelta(16.0, $grandT->getWorldPosition()->x, 1e-5);

        $world->setEntitiesDormant([$child->id, $grandchild->id], true);
        $parentT->position = new Vec3(20.0, 0.0, 0.0);
        $childMatrix = $childT->worldMatrix;
        $world->update(0.016);
        $world->update(0.016);
        $this->assertSame($childMatrix, $childT->worldMatrix, 'a sleeping child is not recomputed');
        $this->assertEqualsWithDelta(20.0, $parentT->getWorldPosition()->x, 1e-5);

        $world->setEntitiesDormant([$child->id, $grandchild->id], false);
        $world->update(0.016);
        $this->assertEqualsWithDelta(25.0, $childT->getWorldPosition()->x, 1e-5);
        $this->assertEqualsWithDelta(26.0, $grandT->getWorldPosition()->x, 1e-5);

        // Settled: the next tick leaves the woken subtree alone.
        $settled = $grandT->worldMatrix;
        $world->update(0.016);
        $this->assertSame($settled, $grandT->worldMatrix);
    }

    /** A child that sleeps through ticks where nothing moved needs no catch-up. */
    public function testDormantChildOfAStillParentKeepsItsMatrixOnWake(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $parent = $world->createEntity();
        $parentT = new Transform3D(new Vec3(10.0, 0.0, 0.0));
        $parent->attach($parentT);
        $child = $world->createEntity();
        $childT = new Transform3D(new Vec3(5.0, 0.0, 0.0));
        $child->attach($childT);
        $parentT->addChild($childT, $child->id, $parent->id);
        $world->update(0.016);

        $world->setEntityDormant($child->id, true);
        $world->update(0.016);
        $world->setEntityDormant($child->id, false);
        $before = $childT->worldMatrix;
        $world->update(0.016);
        $this->assertSame($before, $childT->worldMatrix);
        $this->assertEqualsWithDelta(15.0, $childT->getWorldPosition()->x, 1e-5);

        // Its own local edit while asleep is still picked up on wake.
        $world->setEntityDormant($child->id, true);
        $childT->position = new Vec3(7.0, 0.0, 0.0);
        $world->update(0.016);
        $world->setEntityDormant($child->id, false);
        $world->update(0.016);
        $this->assertEqualsWithDelta(17.0, $childT->getWorldPosition()->x, 1e-5);
    }

    public function testAssignmentWakesAnAlreadyRestingEntitySameTick(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $t = new Transform3D(new Vec3(1.0, 0.0, 0.0));
        $world->createEntity()->attach($t);
        $world->update(0.016);
        // A few quiet ticks - the entity goes resting.
        $world->update(0.016);
        $world->update(0.016);

        $t->position = new Vec3(5.0, 0.0, 0.0);
        $world->update(0.016);
        $this->assertEqualsWithDelta(5.0, $t->getWorldPosition()->x, 1e-5);
    }

    public function testTouchWakesImmediatelyEvenInALargeRestingSet(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        /** @var list<Transform3D> $transforms */
        $transforms = [];
        for ($i = 0; $i < 100; $i++) {
            $t = new Transform3D(new Vec3((float) $i, 0.0, 0.0));
            $world->createEntity()->attach($t);
            $transforms[] = $t;
        }
        $world->update(0.016);

        // An in-place write on just ONE of a hundred resting entities, with
        // touch() right after - a 1/32 random sample would very likely miss
        // it on the very next tick, touch() must not depend on that.
        $target = $transforms[50];
        $target->position->x = 500.0;
        $target->touch();
        $world->update(0.016);

        $this->assertEqualsWithDelta(500.0, $target->getWorldPosition()->x, 1e-5);
    }

    public function testSafetyNetCatchesAnInPlaceWriteAndStaysPermanentlyActive(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $t = new Transform3D(new Vec3(1.0, 0.0, 0.0));
        $world->createEntity()->attach($t);
        $world->update(0.016);

        $t->position->x = 42.0;
        // One resting entity: the safety net's minimum budget (1) already
        // covers the whole resting set every tick, but loop with margin in
        // case the budget formula ever changes.
        for ($i = 0; $i < 32; $i++) {
            $world->update(0.016);
        }
        $this->assertEqualsWithDelta(42.0, $t->getWorldPosition()->x, 1e-5);

        // Caught once, it never needs the safety net again - a SECOND
        // in-place write is picked up the very next tick.
        $t->position->x = 99.0;
        $world->update(0.016);
        $this->assertEqualsWithDelta(99.0, $t->getWorldPosition()->x, 1e-5);
    }

    /**
     * The safety net samples a bounded slice of the resting set per tick
     * (budget = max(1, ceil(count/32))), not the whole set - the reason
     * resting entities cost (almost) nothing instead of a per-entity compare
     * every tick. Mutating every one of a 64-entity resting set in place at
     * once and ticking once must catch only a handful, not all 64; ticking
     * enough times must eventually catch every one of them.
     */
    public function testSafetyNetSamplesABoundedSliceNotTheWholeRestingSet(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        /** @var list<Transform3D> $transforms */
        $transforms = [];
        for ($i = 0; $i < 64; $i++) {
            $t = new Transform3D(new Vec3((float) $i, 0.0, 0.0));
            $world->createEntity()->attach($t);
            $transforms[] = $t;
        }
        $world->update(0.016); // warm: every entity processed once, now resting

        foreach ($transforms as $i => $t) {
            $t->position->x = 1000.0 + $i;
        }

        $world->update(0.016);
        $caughtAfterOneTick = 0;
        foreach ($transforms as $t) {
            if ($t->getWorldPosition()->x >= 1000.0) {
                $caughtAfterOneTick++;
            }
        }
        $this->assertGreaterThan(0, $caughtAfterOneTick);
        $this->assertLessThan(
            64,
            $caughtAfterOneTick,
            'the safety net must sample a slice of the resting set, not compare it whole every tick',
        );

        // The budget shrinks as the resting set does (max(1, ceil(remaining/32))),
        // so clearing all 64 takes more than 64/2 ticks once it drops to a
        // budget of 1 below 32 remaining - 100 ticks is a generous margin.
        for ($tick = 0; $tick < 100; $tick++) {
            $world->update(0.016);
        }
        foreach ($transforms as $i => $t) {
            $this->assertEqualsWithDelta(1000.0 + $i, $t->getWorldPosition()->x, 1e-5);
        }
    }

    public function testMovedParentUpdatesAnAlreadyRestingChild(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $parent = $world->createEntity();
        $parentT = new Transform3D(new Vec3(0.0, 0.0, 0.0));
        $parent->attach($parentT);
        $child = $world->createEntity();
        $childT = new Transform3D(new Vec3(5.0, 0.0, 0.0));
        $child->attach($childT);
        $parentT->addChild($childT, $child->id, $parent->id);

        $world->update(0.016);
        $this->assertEqualsWithDelta(5.0, $childT->getWorldPosition()->x, 1e-5);

        // Several quiet ticks - the child goes resting, same as a static leaf.
        for ($i = 0; $i < 5; $i++) {
            $world->update(0.016);
        }
        $childMatrixBefore = $childT->worldMatrix;

        $parentT->position = new Vec3(10.0, 0.0, 0.0);
        $world->update(0.016);

        $this->assertEqualsWithDelta(15.0, $childT->getWorldPosition()->x, 1e-5);
        $this->assertNotSame(
            $childMatrixBefore,
            $childT->worldMatrix,
            'a resting child must get a fresh matrix when its parent moves',
        );
    }

    public function testRestingEntityKeepsTheSameMatrixInstanceAcrossTicks(): void
    {
        $world = new World();
        $world->addSystem(new Transform3DSystem());

        $t = new Transform3D(new Vec3(1.0, 0.0, 0.0));
        $world->createEntity()->attach($t);
        $world->update(0.016);
        $matrix = $t->worldMatrix;

        for ($i = 0; $i < 10; $i++) {
            $world->update(0.016);
            $this->assertSame($matrix, $t->worldMatrix);
        }
    }

    public function testMovedThisTickContent(): void
    {
        $world = new World();
        $system = new Transform3DSystem();
        $world->addSystem($system);

        $a = $world->createEntity();
        $a->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)));
        $b = $world->createEntity();
        $bt = new Transform3D(new Vec3(1.0, 0.0, 0.0));
        $b->attach($bt);

        // First tick: both freshly attached, both get their first matrix.
        $world->update(0.016);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $system->movedThisTick());

        // Nothing changed.
        $world->update(0.016);
        $this->assertSame([], $system->movedThisTick());

        $bt->position = new Vec3(9.0, 0.0, 0.0);
        $world->update(0.016);
        $this->assertSame([$b->id], $system->movedThisTick());
    }

    /**
     * Unlike movedThisTick() (reset every update() call), drainMovedSinceLastDrain()
     * accumulates across several update() calls and is only cleared by its
     * own call - GameLoop::runPipelined() can run more than one fixed tick
     * before the next render, so a consumer that drains once per rendered
     * frame must not lose a mover from an earlier tick in that frame.
     */
    public function testDrainMovedSinceLastDrainAccumulatesAcrossTicks(): void
    {
        $world = new World();
        $system = new Transform3DSystem();
        $world->addSystem($system);

        $a = $world->createEntity();
        $at = new Transform3D(new Vec3(0.0, 0.0, 0.0));
        $a->attach($at);
        $b = $world->createEntity();
        $bt = new Transform3D(new Vec3(1.0, 0.0, 0.0));
        $b->attach($bt);

        // First tick: both freshly attached - drain it so the next assertions
        // only see moves caused by the two ticks below.
        $world->update(0.016);
        $system->drainMovedSinceLastDrain();

        // Two ticks run before anything drains - simulating a render that
        // falls behind and GameLoop::runPipelined() catching up with more
        // than one fixed-timestep update() before the next render.
        $at->position = new Vec3(5.0, 0.0, 0.0);
        $world->update(0.016);
        $bt->position = new Vec3(9.0, 0.0, 0.0);
        $world->update(0.016);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $system->drainMovedSinceLastDrain());
        // Drained - nothing left until something moves again.
        $this->assertSame([], $system->drainMovedSinceLastDrain());
    }
}
