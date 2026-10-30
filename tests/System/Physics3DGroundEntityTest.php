<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * CharacterController3D::$groundEntityId names what isGrounded stands on, at
 * every place the physics sets isGrounded - and is only ever set there, so the
 * two can never disagree about whether a character has ground.
 */
final class Physics3DGroundEntityTest extends TestCase
{
    private const float HEIGHT = 1.8;
    private const float RADIUS = 0.3;
    private const float DT = 1.0 / 60.0;

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        parent::tearDown();
    }

    public function testStandingOnABox(): void
    {
        $world = $this->world();
        $floor = $this->boxFloor($world, 0.0);
        [$player, $controller] = $this->player($world, 2.0);

        $this->settle($world);

        $this->assertTrue($controller->isGrounded);
        $this->assertSame($floor->id, $controller->groundEntityId);
        $this->assertEqualsWithDelta(self::HEIGHT / 2.0, $player->get(Transform3D::class)->position->y, 0.02);
    }

    public function testStandingOnAMesh(): void
    {
        $world = $this->world();
        $floor = $this->meshFloor($world, 0.0);
        [, $controller] = $this->player($world, 2.0);

        $this->settle($world);

        $this->assertTrue($controller->isGrounded);
        $this->assertSame($floor->id, $controller->groundEntityId);
    }

    public function testStandingOnARotatedBox(): void
    {
        $world = $this->world();
        $floor = $world->createEntity();
        $floor->attach(new Transform3D(
            position: new Vec3(0.0, -1.0, 0.0),
            rotation: Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.4),
        ));
        $floor->attach(new BoxCollider3D(size: new Vec3(20.0, 2.0, 20.0), isStatic: true));
        [, $controller] = $this->player($world, 2.0);

        $this->settle($world);

        $this->assertTrue($controller->isGrounded);
        $this->assertSame($floor->id, $controller->groundEntityId);
    }

    /** A character resting exactly on a surface is held by the swept probe alone, not by an overlap. */
    #[DataProvider('floors')]
    public function testTheContactHoldsOnEveryTickAtRest(string $kind): void
    {
        $world = $this->world();
        $floor = $kind === 'box' ? $this->boxFloor($world, 0.0) : $this->meshFloor($world, 0.0);
        [, $controller] = $this->player($world, 0.5);
        $this->settle($world);

        for ($i = 0; $i < 120; $i++) {
            $world->update(self::DT);
            $this->assertSame($floor->id, $controller->groundEntityId, "tick {$i}");
        }
    }

    /** A fall carries the feet past a thin floor in one step: only the swept landing can name it. */
    #[DataProvider('floors')]
    public function testASweptLandingNamesTheFloor(string $kind): void
    {
        $world = $this->world();
        $floor = $kind === 'box' ? $this->boxFloor($world, 0.0, 0.025) : $this->meshFloor($world, 0.0);
        [, $controller] = $this->player($world, 3.0);
        $controller->velocity = new Vec3(0.0, -60.0, 0.0);

        $world->update(self::DT * 4.0);

        $this->assertTrue($controller->isGrounded);
        $this->assertSame($floor->id, $controller->groundEntityId);
    }

    /** @return iterable<string, array{string}> */
    public static function floors(): iterable
    {
        yield 'box' => ['box'];
        yield 'mesh' => ['mesh'];
    }

    public function testClimbingAStepHandsTheContactToTheStep(): void
    {
        $world = $this->world();
        $this->boxFloor($world, 0.0);
        $step = $world->createEntity();
        $step->attach(new Transform3D(position: new Vec3(3.0, 0.1, 0.0)));
        $step->attach(new BoxCollider3D(size: new Vec3(2.0, 0.2, 4.0), isStatic: true));
        [$player, $controller] = $this->player($world, 0.0);
        $this->settle($world);

        $this->walkUntilLifted($world, $player);

        // On the very tick of the lift, not one later when the resting probe sees it.
        $this->assertSame($step->id, $controller->groundEntityId);
        $this->assertEqualsWithDelta(0.2 + self::HEIGHT / 2.0, $player->get(Transform3D::class)->position->y, 0.02);
    }

    public function testClimbingARotatedStepHandsTheContactToTheStep(): void
    {
        $world = $this->world();
        $this->boxFloor($world, 0.0);
        $step = $world->createEntity();
        $step->attach(new Transform3D(
            position: new Vec3(3.0, 0.1, 0.0),
            rotation: Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.3),
        ));
        $step->attach(new BoxCollider3D(size: new Vec3(2.0, 0.2, 4.0), isStatic: true));
        [$player, $controller] = $this->player($world, 0.0);
        $this->settle($world);

        $this->walkUntilLifted($world, $player);

        $this->assertSame($step->id, $controller->groundEntityId);
    }

    /** Sunk into a floor (a teleport, a floor that rose): the overlap pushes the feet up, the sweep cannot see it. */
    #[DataProvider('floors')]
    public function testBeingPushedOutOfAFloorNamesIt(string $kind): void
    {
        $world = $this->world();
        $floor = $kind === 'box' ? $this->boxFloor($world, 0.0) : $this->meshFloor($world, 0.0);
        [, $controller] = $this->player($world, -0.1);

        $world->update(self::DT);

        $this->assertTrue($controller->isGrounded);
        $this->assertSame($floor->id, $controller->groundEntityId);
    }

    public function testWalkingAcrossAJointChangesTheFloor(): void
    {
        $world = $this->world();
        $left = $this->boxFloor($world, 0.0, 0.5, new Vec3(-5.0, 0.0, 0.0), 5.0);
        $right = $this->meshFloor($world, 0.0, new Vec3(5.0, 0.0, 0.0), 10.0);
        [$player, $controller] = $this->player($world, 0.0, new Vec3(-2.0, 0.0, 0.0));
        $this->settle($world);
        $this->assertSame($left->id, $controller->groundEntityId);

        $transform = $player->get(Transform3D::class);
        $seen = [];
        for ($i = 0; $i < 120; $i++) {
            $transform->position = $transform->position->add(new Vec3(0.05, 0.0, 0.0));
            $world->update(self::DT);
            $seen[$controller->groundEntityId ?? -1] = true;
        }

        $this->assertSame($right->id, $controller->groundEntityId);
        $this->assertArrayNotHasKey(-1, $seen, 'no tick without ground on a flush joint');
    }

    public function testATriggerIsNeverTheGround(): void
    {
        $world = $this->world();
        $floor = $this->boxFloor($world, 0.0);
        $volume = $world->createEntity();
        $volume->attach(new Transform3D(position: new Vec3(0.0, 1.0, 0.0)));
        $volume->attach(new BoxCollider3D(size: new Vec3(20.0, 2.0, 20.0), isStatic: true, isTrigger: true));
        MeshRegistry::register('test_trigger_plane', PlaneMesh::generate(20.0, 20.0));
        $sheet = $world->createEntity();
        $sheet->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));
        $sheet->attach(new MeshCollider3D(meshId: 'test_trigger_plane', isTrigger: true));
        [, $controller] = $this->player($world, 1.0);

        $this->settle($world);

        $this->assertSame($floor->id, $controller->groundEntityId);
    }

    public function testSwitchingAFloorToTriggerDropsItFromTheNextTick(): void
    {
        $world = $this->world();
        $upper = $this->meshFloor($world, 2.0);
        $lower = $this->boxFloor($world, 0.0);
        [, $controller] = $this->player($world, 2.0);
        $this->settle($world);
        $this->assertSame($upper->id, $controller->groundEntityId);

        $upper->get(MeshCollider3D::class)->isTrigger = true;
        $world->update(self::DT);
        $this->assertNull($controller->groundEntityId, 'falling now');

        $this->settle($world);
        $this->assertSame($lower->id, $controller->groundEntityId);
    }

    public function testInTheAirThereIsNoGround(): void
    {
        $world = $this->world();
        $floor = $this->boxFloor($world, 0.0);
        [, $controller] = $this->player($world, 0.0);
        $this->settle($world);
        $this->assertSame($floor->id, $controller->groundEntityId);

        $controller->isGrounded = false;
        $controller->velocity = new Vec3(0.0, 8.0, 0.0);
        $world->update(self::DT);

        $this->assertFalse($controller->isGrounded);
        $this->assertNull($controller->groundEntityId);
    }

    public function testTheBarePlaneIsGroundWithoutAnEntity(): void
    {
        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: 0.0));
        [, $controller] = $this->player($world, 0.5);

        $this->settle($world);

        $this->assertTrue($controller->isGrounded);
        $this->assertNull($controller->groundEntityId);
    }

    public function testAFloorLevelWithThePlaneStaysTheGround(): void
    {
        // The default plane sits at y = 0, exactly where many floors have their
        // top: the backstop then fires every tick and must not erase the contact.
        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: 0.0));
        $floor = $this->boxFloor($world, 0.0);
        [, $controller] = $this->player($world, 0.5);

        $this->settle($world);

        $this->assertSame($floor->id, $controller->groundEntityId);
    }

    public function testAHeightmapIsTheGround(): void
    {
        $world = $this->world();
        $terrain = $world->createEntity();
        $heightmap = new HeightmapCollider3D(8, 8, -50.0, 50.0, -50.0, 50.0);
        $heightmap->populateFromFunction(static fn (float $x, float $z): float => 0.0);
        $terrain->attach(new Transform3D());
        $terrain->attach($heightmap);
        [, $controller] = $this->player($world, 0.5);

        // Falls until the feet are below the surface: the first tick it is grounded.
        for ($i = 0; $i < 120 && !$controller->isGrounded; $i++) {
            $world->update(self::DT);
        }

        $this->assertTrue($controller->isGrounded);
        $this->assertSame($terrain->id, $controller->groundEntityId);
    }

    public function testCouplingClearsTheGround(): void
    {
        $world = $this->world();
        $floor = $this->boxFloor($world, 0.0);
        [, $controller] = $this->player($world, 0.0);
        $this->settle($world);
        $this->assertSame($floor->id, $controller->groundEntityId);

        $controller->coupleTo($floor->id, new Vec3(0.0, 2.0, 0.0));

        $this->assertNull($controller->groundEntityId);
    }

    public function testTheLastSubstepDecides(): void
    {
        $world = $this->world();
        $floor = $this->meshFloor($world, 0.0);
        [, $controller] = $this->player($world, 0.2);

        $world->update(0.25); // 8 substeps, the first ones still falling

        $this->assertSame($floor->id, $controller->groundEntityId);
    }

    public function testControllersDoNotShareTheirGround(): void
    {
        $world = $this->world();
        $left = $this->boxFloor($world, 0.0, 0.5, new Vec3(-20.0, 0.0, 0.0), 5.0);
        $right = $this->meshFloor($world, 0.0, new Vec3(20.0, 0.0, 0.0), 10.0);
        [, $a] = $this->player($world, 0.5, new Vec3(-20.0, 0.0, 0.0));
        [, $b] = $this->player($world, 0.5, new Vec3(20.0, 0.0, 0.0));

        $this->settle($world);

        $this->assertSame($left->id, $a->groundEntityId);
        $this->assertSame($right->id, $b->groundEntityId);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function world(): World
    {
        $world = new World();
        // Far backstop: the floors decide, never the plane.
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -125.0));

        return $world;
    }

    private function settle(World $world): void
    {
        for ($i = 0; $i < 60; $i++) {
            $world->update(self::DT);
        }
    }

    private function boxFloor(World $world, float $top, float $halfThickness = 0.5, ?Vec3 $at = null, float $halfSize = 11.0): Entity
    {
        $at ??= Vec3::zero();
        $floor = $world->createEntity();
        $floor->attach(new Transform3D(position: new Vec3($at->x, $top - $halfThickness, $at->z)));
        $floor->attach(new BoxCollider3D(
            size: new Vec3($halfSize * 2.0, $halfThickness * 2.0, $halfSize * 2.0),
            isStatic: true,
        ));

        return $floor;
    }

    private function meshFloor(World $world, float $top, ?Vec3 $at = null, float $size = 22.0): Entity
    {
        $at ??= Vec3::zero();
        $meshId = 'test_ground_plane_' . (int) $size;
        MeshRegistry::register($meshId, PlaneMesh::generate($size, $size));
        $floor = $world->createEntity();
        $floor->attach(new Transform3D(position: new Vec3($at->x, $top, $at->z)));
        $floor->attach(new MeshCollider3D(meshId: $meshId));

        return $floor;
    }

    /** Walks along +X (games move characters by position) until a step lifts the feet. */
    private function walkUntilLifted(World $world, Entity $player): void
    {
        $transform = $player->get(Transform3D::class);
        $standing = $transform->position->y;
        for ($i = 0; $i < 90 && $transform->position->y < $standing + 0.1; $i++) {
            $transform->position = $transform->position->add(new Vec3(0.05, 0.0, 0.0));
            $world->update(self::DT);
        }
        $this->assertGreaterThan($standing + 0.1, $transform->position->y, 'never climbed the step');
    }

    /** @return array{Entity, CharacterController3D} */
    private function player(World $world, float $feetY, ?Vec3 $at = null): array
    {
        $at ??= Vec3::zero();
        $player = $world->createEntity();
        $player->attach(new Transform3D(position: new Vec3($at->x, $feetY + self::HEIGHT / 2.0, $at->z)));
        $controller = new CharacterController3D(height: self::HEIGHT, radius: self::RADIUS);
        $player->attach($controller);

        return [$player, $controller];
    }
}
