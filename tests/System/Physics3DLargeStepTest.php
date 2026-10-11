<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * A character standing on (or falling onto) a thin floor must stay on top of it
 * no matter how long a single tick is: a slow machine, a loading hitch or a
 * debugger pause hands the physics a dt far above the usual frame time, and a
 * purely discrete overlap test then lets the capsule pass through any collider
 * thinner than the distance it travels in that one step.
 */
final class Physics3DLargeStepTest extends TestCase
{
    private const float FLOOR_TOP = 40.0;
    private const float HEIGHT = 1.8;
    private const float RADIUS = 0.3;
    private const float STANDING_Y = self::FLOOR_TOP + self::HEIGHT / 2.0;
    private const float TOLERANCE = 0.02;

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        parent::tearDown();
    }

    /** @return iterable<string, array{float}> */
    public static function largeSteps(): iterable
    {
        foreach ([0.1, 0.25, 0.5, 1.0, 2.0] as $dt) {
            yield "dt {$dt}" => [$dt];
        }
    }

    #[DataProvider('largeSteps')]
    public function testCharacterStaysOnAThinBoxFloorAtLargeSteps(float $dt): void
    {
        [$world, $player] = $this->roomWithBoxFloor(halfThickness: 0.5);

        for ($i = 0; $i < 10; $i++) {
            $world->update($dt);
            $this->assertStanding($player, "tick {$i} at dt {$dt}");
        }
    }

    #[DataProvider('largeSteps')]
    public function testCharacterStaysOnAThinMeshFloorAtLargeSteps(float $dt): void
    {
        [$world, $player] = $this->roomWithMeshFloor();

        for ($i = 0; $i < 10; $i++) {
            $world->update($dt);
            $this->assertStanding($player, "tick {$i} at dt {$dt}");
        }
    }

    public function testASingleHugeSpikeAfterNormalFramesKeepsTheCharacterUp(): void
    {
        [$world, $player] = $this->roomWithBoxFloor(halfThickness: 0.5);

        for ($i = 0; $i < 60; $i++) {
            $world->update(1.0 / 60.0);
        }
        $this->assertStanding($player, 'before the spike');

        $world->update(2.0);
        $this->assertStanding($player, 'right after a 2 s spike');

        for ($i = 0; $i < 30; $i++) {
            $world->update(1.0 / 60.0);
            $this->assertStanding($player, "frame {$i} after the spike");
        }
    }

    public function testAFastFallLandsOnTopOfAPaperThinFloor(): void
    {
        // 5 cm thick slab, character dropping at 60 m/s: 1 m per 60 Hz tick.
        [$world, $player] = $this->roomWithBoxFloor(halfThickness: 0.025);
        $player->get(Transform3D::class)->position = new Vec3(0.0, self::STANDING_Y + 3.0, 0.0);
        $player->get(CharacterController3D::class)->velocity = new Vec3(0.0, -60.0, 0.0);

        for ($i = 0; $i < 30; $i++) {
            $world->update(1.0 / 60.0);
        }

        $this->assertStanding($player, 'after a 60 m/s fall onto a 5 cm slab');
        $this->assertTrue($player->get(CharacterController3D::class)->isGrounded);
    }

    public function testAFastFallLandsOnTopOfAMeshFloor(): void
    {
        [$world, $player] = $this->roomWithMeshFloor();
        $player->get(Transform3D::class)->position = new Vec3(0.0, self::STANDING_Y + 3.0, 0.0);
        $player->get(CharacterController3D::class)->velocity = new Vec3(0.0, -60.0, 0.0);

        for ($i = 0; $i < 30; $i++) {
            $world->update(1.0 / 60.0);
        }

        $this->assertStanding($player, 'after a 60 m/s fall onto a single-sided mesh floor');
    }

    public function testRestingCharacterDoesNotSinkOverManyNormalFrames(): void
    {
        [$world, $player] = $this->roomWithBoxFloor(halfThickness: 0.5);

        for ($i = 0; $i < 600; $i++) {
            $world->update(1.0 / 60.0);
        }

        $this->assertEqualsWithDelta(
            self::STANDING_Y,
            $player->get(Transform3D::class)->position->y,
            1e-6,
            'a character at rest must not drift into the floor',
        );
    }

    public function testANormalFrameIsStillOneStep(): void
    {
        // dt at or below the substep size integrates exactly as before:
        // v = g·dt, y -= v·dt (semi-implicit Euler, one step).
        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -1000.0));
        $player = $world->createEntity();
        $player->attach(new Transform3D(position: new Vec3(0.0, 10.0, 0.0)));
        $player->attach(new CharacterController3D(height: self::HEIGHT, radius: self::RADIUS));

        $dt = 1.0 / 60.0;
        $world->update($dt);

        $this->assertEqualsWithDelta(10.0 - 20.0 * $dt * $dt, $player->get(Transform3D::class)->position->y, 1e-9);
    }

    public function testALongTickIsSplitIntoSubsteps(): void
    {
        // Free fall over 0.2 s: one 0.2 s Euler step drops 20·0.2² = 0.8 m;
        // split into substeps of at most MAX_SUBSTEP the drop approaches the
        // exact ½·g·t² = 0.4 m from above.
        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -1000.0));
        $player = $world->createEntity();
        $player->attach(new Transform3D(position: new Vec3(0.0, 10.0, 0.0)));
        $player->attach(new CharacterController3D(height: self::HEIGHT, radius: self::RADIUS));

        $world->update(0.2);

        $n = (int) ceil(0.2 / Physics3DSystem::MAX_SUBSTEP - 1e-9);
        $h = 0.2 / $n;
        $expectedDrop = 20.0 * $h * $h * $n * ($n + 1) / 2.0;
        $this->assertEqualsWithDelta(10.0 - $expectedDrop, $player->get(Transform3D::class)->position->y, 1e-9);
    }

    public function testTimeBeyondTheSubstepBudgetIsDropped(): void
    {
        // A multi-second stall must not fling characters: the physics simulates
        // at most MAX_SUBSTEPS × MAX_SUBSTEP of it.
        $fall = static function (float $dt): float {
            $world = new World();
            $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -1000.0));
            $player = $world->createEntity();
            $player->attach(new Transform3D(position: new Vec3(0.0, 10.0, 0.0)));
            $player->attach(new CharacterController3D(height: self::HEIGHT, radius: self::RADIUS));
            $world->update($dt);
            return $player->get(Transform3D::class)->position->y;
        };

        $budget = Physics3DSystem::MAX_SUBSTEPS * Physics3DSystem::MAX_SUBSTEP;
        $this->assertEqualsWithDelta($fall($budget), $fall(5.0), 1e-9);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /** @return array{World, \PHPolygon\ECS\Entity} */
    private function roomWithBoxFloor(float $halfThickness): array
    {
        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -125.0));

        $floor = $world->createEntity();
        $floor->attach(new Transform3D(
            position: new Vec3(0.0, self::FLOOR_TOP - $halfThickness, 0.0),
            scale: new Vec3(11.0, $halfThickness, 11.0),
        ));
        $floor->attach(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));

        return [$world, $this->player($world)];
    }

    /** @return array{World, \PHPolygon\ECS\Entity} */
    private function roomWithMeshFloor(): array
    {
        MeshRegistry::register('test_floor_plane', PlaneMesh::generate(22.0, 22.0));

        $world = new World();
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -125.0));

        $floor = $world->createEntity();
        $floor->attach(new Transform3D(position: new Vec3(0.0, self::FLOOR_TOP, 0.0)));
        $floor->attach(new MeshCollider3D(meshId: 'test_floor_plane'));

        return [$world, $this->player($world)];
    }

    private function player(World $world): \PHPolygon\ECS\Entity
    {
        $player = $world->createEntity();
        $player->attach(new Transform3D(position: new Vec3(0.0, self::STANDING_Y, 0.0)));
        $player->attach(new CharacterController3D(height: self::HEIGHT, radius: self::RADIUS));

        return $player;
    }

    private function assertStanding(\PHPolygon\ECS\Entity $player, string $when): void
    {
        $y = $player->get(Transform3D::class)->position->y;
        $this->assertGreaterThanOrEqual(self::STANDING_Y - self::TOLERANCE, $y, "fell into/through the floor {$when}");
        $this->assertLessThanOrEqual(self::STANDING_Y + self::TOLERANCE, $y, "floats above the floor {$when}");
    }
}
