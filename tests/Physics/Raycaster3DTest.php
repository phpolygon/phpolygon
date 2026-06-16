<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Ray;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\Raycaster3D;

class Raycaster3DTest extends TestCase
{
    private function spawnBox(World $world, Vec3 $position, Vec3 $size): int
    {
        $entity = $world->createEntity();
        $entity->attach(new Transform3D($position));
        $entity->attach(new BoxCollider3D($size));
        return $entity->id;
    }

    public function testRaycastHitsBoxInPath(): void
    {
        $world = new World();
        // Unit box centred at (10,0,0) => spans x in [9.5, 10.5].
        $id = $this->spawnBox($world, new Vec3(10, 0, 0), new Vec3(1, 1, 1));

        $caster = new Raycaster3D();
        $ray = new Ray(new Vec3(0, 0, 0), new Vec3(1, 0, 0));
        $hit = $caster->raycast($world, $ray);

        $this->assertNotNull($hit);
        $this->assertSame($id, $hit->entityId);
        // Front face of the box at x = 9.5.
        $this->assertEqualsWithDelta(9.5, $hit->distance, 1e-4);
        $this->assertTrue($hit->point->equals(new Vec3(9.5, 0, 0)), (string)$hit->point);
        // Normal of the -x face.
        $this->assertTrue($hit->normal->equals(new Vec3(-1, 0, 0)), (string)$hit->normal);
    }

    public function testRaycastMissesBoxOffAxis(): void
    {
        $world = new World();
        $this->spawnBox($world, new Vec3(10, 0, 0), new Vec3(1, 1, 1));

        $caster = new Raycaster3D();
        // Ray parallel to the box but offset in y, far above it.
        $ray = new Ray(new Vec3(0, 50, 0), new Vec3(1, 0, 0));
        $this->assertNull($caster->raycast($world, $ray));
    }

    public function testRaycastRespectsMaxDistance(): void
    {
        $world = new World();
        $this->spawnBox($world, new Vec3(100, 0, 0), new Vec3(1, 1, 1));

        $caster = new Raycaster3D();
        $ray = new Ray(new Vec3(0, 0, 0), new Vec3(1, 0, 0));
        // Box is ~99.5 away; cap below that.
        $this->assertNull($caster->raycast($world, $ray, 50.0));
        $this->assertNotNull($caster->raycast($world, $ray, 200.0));
    }

    public function testRaycastAllReturnsNearestFirst(): void
    {
        $world = new World();
        $far = $this->spawnBox($world, new Vec3(20, 0, 0), new Vec3(1, 1, 1));
        $near = $this->spawnBox($world, new Vec3(5, 0, 0), new Vec3(1, 1, 1));

        $caster = new Raycaster3D();
        $ray = new Ray(new Vec3(0, 0, 0), new Vec3(1, 0, 0));
        $hits = $caster->raycastAll($world, $ray);

        $this->assertCount(2, $hits);
        $this->assertSame($near, $hits[0]->entityId);
        $this->assertSame($far, $hits[1]->entityId);
        $this->assertLessThan($hits[1]->distance, $hits[0]->distance);
    }

    public function testRaycastEmptyWorldReturnsNull(): void
    {
        $world = new World();
        $caster = new Raycaster3D();
        $ray = new Ray(new Vec3(0, 0, 0), new Vec3(1, 0, 0));
        $this->assertNull($caster->raycast($world, $ray));
        $this->assertSame([], $caster->raycastAll($world, $ray));
    }

    public function testRaycastAccountsForScale(): void
    {
        $world = new World();
        // Unit box scaled 4x => half-size 2 => front face at x = 10 - 2 = 8.
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(new Vec3(10, 0, 0), null, new Vec3(4, 4, 4)));
        $entity->attach(new BoxCollider3D(new Vec3(1, 1, 1)));

        $caster = new Raycaster3D();
        $ray = new Ray(new Vec3(0, 0, 0), new Vec3(1, 0, 0));
        $hit = $caster->raycast($world, $ray);

        $this->assertNotNull($hit);
        $this->assertEqualsWithDelta(8.0, $hit->distance, 1e-4);
    }
}
