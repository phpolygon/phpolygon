<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * `new Physics3DSystem(lazyMeshBvh: true)` builds a mesh collider's BVH when a query first reaches its
 * bounds: same character behaviour as the eager build, and colliders nobody comes near stay unbuilt.
 */
final class Physics3DLazyMeshBvhTest extends TestCase
{
    private const int TILES = 8;

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        parent::tearDown();
    }

    /** @return array{World, CharacterController3D, Transform3D, list<MeshCollider3D>} */
    private function room(bool $lazy): array
    {
        MeshRegistry::register('lazy_bvh_tile', PlaneMesh::generate(10.0, 10.0));

        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -125.0, lazyMeshBvh: $lazy));

        $colliders = [];
        for ($i = 0; $i < self::TILES; $i++) {
            $floor = $world->createEntity();
            $floor->attach(new Transform3D(position: new Vec3($i * 10.0, 0.0, 0.0)));
            $floor->attach(new MeshCollider3D(meshId: 'lazy_bvh_tile'));
            $colliders[] = $floor->get(MeshCollider3D::class);
        }

        $player = $world->createEntity();
        $player->attach(new Transform3D(position: new Vec3(1.0, 3.0, 0.0)));
        $player->attach(new CharacterController3D(height: 1.8, radius: 0.3));
        $transform = $player->get(Transform3D::class);
        $controller = $player->get(CharacterController3D::class);

        return [$world, $controller, $transform, $colliders];
    }

    public function testWalkingAcrossTilesGivesTheSamePositionsAsTheEagerBuild(): void
    {
        [$eagerWorld, , $eagerTransform] = $this->room(false);
        [$lazyWorld, , $lazyTransform] = $this->room(true);

        for ($tick = 0; $tick < 240; $tick++) {
            foreach ([[$eagerWorld, $eagerTransform], [$lazyWorld, $lazyTransform]] as [$world, $transform]) {
                if ($tick > 40) {
                    $transform->position = new Vec3($transform->position->x + 0.12, $transform->position->y, $transform->position->z);
                }
                $world->update(1 / 60);
            }
            $this->assertEqualsWithDelta($eagerTransform->position->x, $lazyTransform->position->x, 1e-12, "x at tick {$tick}");
            $this->assertEqualsWithDelta($eagerTransform->position->y, $lazyTransform->position->y, 1e-12, "y at tick {$tick}");
        }
        $this->assertGreaterThan(20.0,$lazyTransform->position->x, 'the character walked over several tiles');
        $this->assertEqualsWithDelta(0.9, $lazyTransform->position->y, 0.05, 'and stands on the floor');
    }

    public function testOnlyTheCollidersNearTheCharacterAreBuilt(): void
    {
        [$world, , , $colliders] = $this->room(true);
        for ($tick = 0; $tick < 60; $tick++) {
            $world->update(1 / 60);
        }

        $built = array_map(static fn (MeshCollider3D $c): bool => $c->bvh !== null && $c->bvh->isBuilt(), $colliders);
        $this->assertTrue($built[0], 'the tile under the character is built');
        $this->assertFalse($built[self::TILES - 1], 'a tile 70 m away is not');
        $this->assertLessThan(self::TILES, count(array_filter($built)));

        foreach ($colliders as $collider) {
            $this->assertNotNull($collider->bvh, 'every collider has its (possibly deferred) BVH after the first tick');
        }
    }

    public function testEagerBuildStaysTheDefault(): void
    {
        [$world, , , $colliders] = $this->room(false);
        $world->update(1 / 60);
        foreach ($colliders as $collider) {
            $this->assertNotNull($collider->bvh);
            $this->assertTrue($collider->bvh->isBuilt());
        }
    }
}
