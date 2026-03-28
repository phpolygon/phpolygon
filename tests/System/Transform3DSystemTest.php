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
}
