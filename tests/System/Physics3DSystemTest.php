<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

class Physics3DSystemTest extends TestCase
{
    public function testGravityPullsCharacterDown(): void
    {
        $world = new World();
        $system = new Physics3DSystem();
        $world->addSystem($system);

        $entity = $world->createEntity();
        $world->attachComponent($entity->id, new Transform3D(position: new Vec3(0.0, 10.0, 0.0)));
        $world->attachComponent($entity->id, new CharacterController3D());

        $world->update(0.1);

        $t = $world->getComponent($entity->id, Transform3D::class);
        $this->assertLessThan(10.0, $t->position->y, 'Character should fall');
    }

    public function testCharacterLandsOnGround(): void
    {
        $world = new World();
        $system = new Physics3DSystem();
        $world->addSystem($system);

        $entity = $world->createEntity();
        $world->attachComponent($entity->id, new Transform3D(position: new Vec3(0.0, 0.5, 0.0)));
        $world->attachComponent($entity->id, new CharacterController3D(height: 1.0));

        // Run several frames
        for ($i = 0; $i < 60; $i++) {
            $world->update(0.016);
        }

        $t = $world->getComponent($entity->id, Transform3D::class);
        $cc = $world->getComponent($entity->id, CharacterController3D::class);
        $this->assertEqualsWithDelta(0.5, $t->position->y, 0.01, 'Should rest at halfHeight');
        $this->assertTrue($cc->isGrounded);
    }

    public function testAABBResolveOverlap(): void
    {
        $resolution = Physics3DSystem::resolveAABB(
            new Vec3(0.0, 0.0, 0.0), new Vec3(2.0, 2.0, 2.0), // A
            new Vec3(1.5, 0.0, 0.0), new Vec3(3.5, 2.0, 2.0), // B — overlaps 0.5 in X
        );

        $this->assertNotNull($resolution);
        $this->assertLessThan(0, $resolution->x, 'Should push A left (away from B)');
        $this->assertEqualsWithDelta(0.0, $resolution->y, 0.001);
        $this->assertEqualsWithDelta(0.0, $resolution->z, 0.001);
    }

    public function testAABBNoOverlap(): void
    {
        $resolution = Physics3DSystem::resolveAABB(
            new Vec3(0.0, 0.0, 0.0), new Vec3(1.0, 1.0, 1.0),
            new Vec3(5.0, 5.0, 5.0), new Vec3(6.0, 6.0, 6.0),
        );

        $this->assertNull($resolution);
    }

    public function testAABBResolveMinimumAxis(): void
    {
        // Overlap: X=0.2, Y=1.0, Z=1.0 → should resolve along X (smallest)
        $resolution = Physics3DSystem::resolveAABB(
            new Vec3(0.0, 0.0, 0.0), new Vec3(1.0, 1.0, 1.0),
            new Vec3(0.8, 0.0, 0.0), new Vec3(1.8, 1.0, 1.0),
        );

        $this->assertNotNull($resolution);
        $this->assertEqualsWithDelta(-0.2, $resolution->x, 0.001);
        $this->assertEqualsWithDelta(0.0, $resolution->y, 0.001);
        $this->assertEqualsWithDelta(0.0, $resolution->z, 0.001);
    }

    public function testCharacterBlockedByStaticCollider(): void
    {
        $world = new World();
        $system = new Physics3DSystem(new Vec3(0.0, 0.0, 0.0)); // No gravity
        $world->addSystem($system);

        // Character at origin
        $player = $world->createEntity();
        $world->attachComponent($player->id, new Transform3D(position: new Vec3(0.0, 1.0, 0.0)));
        $cc = new CharacterController3D(height: 2.0, radius: 0.5);
        $cc->velocity = new Vec3(10.0, 0.0, 0.0); // Moving right
        $world->attachComponent($player->id, $cc);

        // Wall at x=2
        $wall = $world->createEntity();
        $world->attachComponent($wall->id, new Transform3D(position: new Vec3(2.5, 1.0, 0.0)));
        $world->attachComponent($wall->id, new BoxCollider3D(
            size: new Vec3(1.0, 4.0, 4.0),
            isStatic: true,
        ));

        $world->update(0.016);

        $t = $world->getComponent($player->id, Transform3D::class);
        // Player should not pass through the wall
        $this->assertLessThan(2.0, $t->position->x, 'Player should be pushed back before wall');
    }

    /**
     * @return array{World, Transform3D, CharacterController3D, Transform3D}
     */
    private function playerAgainstWall(): array
    {
        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, 0.0, 0.0)));

        // Wall spans x 2.0..3.0; the player's capsule (radius 0.5) at x 1.6 reaches 2.1.
        $wall = $world->createEntity();
        $wallT = new Transform3D(position: new Vec3(2.5, 1.0, 0.0));
        $world->attachComponent($wall->id, $wallT);
        $world->attachComponent($wall->id, new BoxCollider3D(size: new Vec3(1.0, 4.0, 4.0), isStatic: true));

        $player = $world->createEntity();
        $playerT = new Transform3D(position: new Vec3(1.6, 1.0, 0.0));
        $world->attachComponent($player->id, $playerT);
        $cc = new CharacterController3D(height: 2.0, radius: 0.5);
        $world->attachComponent($player->id, $cc);

        return [$world, $playerT, $cc, $wallT];
    }

    public function testMovedColliderIsResolvedAtItsNewPlace(): void
    {
        [$world, $playerT, $cc, $wallT] = $this->playerAgainstWall();
        $world->update(0.016);
        $this->assertLessThan(1.6, $playerT->position->x, 'the wall pushes the player back');

        // The wall moves away: a new world matrix must invalidate the cached AABB.
        $wallT->position = new Vec3(50.0, 1.0, 0.0);
        $wallT->worldMatrix = $wallT->getLocalMatrix();
        $playerT->position = new Vec3(1.6, 1.0, 0.0);
        $cc->velocity = new Vec3(10.0, 0.0, 0.0);
        $world->update(0.016);
        $this->assertGreaterThan(1.6, $playerT->position->x, 'the player walks where the wall used to be');
    }

    public function testEqualWorldMatrixInANewObjectKeepsTheCollider(): void
    {
        [$world, $playerT, $cc, $wallT] = $this->playerAgainstWall();
        $world->update(0.016);

        // A system that rebuilds every matrix hands over an equal matrix in a new object.
        $wallT->worldMatrix = new \PHPolygon\Math\Mat4($wallT->worldMatrix->toArray());
        $playerT->position = new Vec3(1.6, 1.0, 0.0);
        $cc->velocity = new Vec3(10.0, 0.0, 0.0);
        $world->update(0.016);
        $this->assertLessThan(1.6, $playerT->position->x, 'the wall still blocks');
    }

    public function testCharacterWalksOnTopOfCollider(): void
    {
        $world = new World();
        $system = new Physics3DSystem(); // Default gravity
        $world->addSystem($system);

        // Platform at y=2 (top at y=2.5)
        $platform = $world->createEntity();
        $world->attachComponent($platform->id, new Transform3D(position: new Vec3(0.0, 2.0, 0.0)));
        $world->attachComponent($platform->id, new BoxCollider3D(
            size: new Vec3(10.0, 1.0, 10.0),
            isStatic: true,
        ));

        // Character falling onto platform
        $player = $world->createEntity();
        $world->attachComponent($player->id, new Transform3D(position: new Vec3(0.0, 5.0, 0.0)));
        $world->attachComponent($player->id, new CharacterController3D(height: 1.8, radius: 0.4));

        for ($i = 0; $i < 120; $i++) {
            $world->update(0.016);
        }

        $t = $world->getComponent($player->id, Transform3D::class);
        $cc = $world->getComponent($player->id, CharacterController3D::class);

        // Should land on top of the platform (top=2.5, halfHeight=0.9 → y≈3.4)
        $this->assertGreaterThan(2.5, $t->position->y, 'Should be above platform');
        $this->assertLessThan(4.0, $t->position->y, 'Should not be floating');
    }
}
