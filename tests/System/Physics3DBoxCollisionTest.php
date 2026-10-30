<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\Physics3DSystem;

/**
 * Box colliders against a walking capsule: what counts as rotated, and what a
 * step lift has to ask before it lifts.
 */
final class Physics3DBoxCollisionTest extends TestCase
{
    private const float HEIGHT = 1.8;
    private const float RADIUS = 0.3;
    private const float STEP = 0.65;
    private const float WALK = 5.0;

    private World $world;
    private Transform3D $player;
    private CharacterController3D $controller;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -125.0));
        $this->box(new Vec3(0.0, -0.5, 0.0), new Vec3(60.0, 1.0, 60.0));

        $this->player = new Transform3D(position: new Vec3(-2.0, self::HEIGHT / 2.0, 0.0));
        $this->controller = new CharacterController3D(height: self::HEIGHT, radius: self::RADIUS, stepHeight: self::STEP);
        $this->controller->isGrounded = true;
        $this->world->createEntity()->attach($this->player)->attach($this->controller);
    }

    // ─── What counts as rotated ──────────────────────────────────────────

    /** @return iterable<string, array{float}> */
    public static function slightRotations(): iterable
    {
        yield '1 degree' => [deg2rad(1.0)];
        yield '3 degrees' => [deg2rad(3.0)];
        yield '4.7 degrees' => [0.082];
        yield '4.7 degrees the other way' => [-0.082];
    }

    /**
     * A slightly rotated long rail beside the way: its world AABB is metres deep,
     * the box itself is 10 cm thick and 1.2 m away from the walker.
     */
    #[DataProvider('slightRotations')]
    public function testASlightlyRotatedBoxCollidesAsItsTriangles(float $yaw): void
    {
        $this->box(new Vec3(0.0, 0.6, 1.2), new Vec3(38.0, 1.2, 0.1), $yaw);
        $this->walk(60.0, 0.4);

        $this->assertEqualsWithDelta(0.0, $this->player->position->z, 1e-6, 'pushed by the hull');
        $this->assertEqualsWithDelta(0.0, $this->player->position->x, 1e-6);
    }

    public function testASlightlyRotatedBoxStillStopsAWalker(): void
    {
        $this->box(new Vec3(0.0, 1.5, 0.0), new Vec3(0.4, 3.0, 40.0), 0.082);

        $this->walk(60.0, 1.0);

        $this->assertLessThan(-0.2, $this->player->position->x);
    }

    public function testAnUnrotatedBoxStaysAnAabb(): void
    {
        $box = $this->box(new Vec3(0.0, 1.5, 0.0), new Vec3(0.4, 3.0, 40.0));

        $this->walk(60.0, 0.1);

        $this->assertFalse($box->cachedRotated);
        $this->assertNull($box->bvh);
    }

    public function testAHalfTurnIsRotated(): void
    {
        $box = $this->box(new Vec3(0.0, 1.5, 0.0), new Vec3(0.4, 3.0, 40.0), M_PI);

        $this->walk(60.0, 0.1);

        $this->assertTrue($box->cachedRotated);
    }

    // ─── Headroom of a step lift ─────────────────────────────────────────

    /** @return iterable<string, array{float}> */
    public static function ceilings(): iterable
    {
        yield 'ceiling at 1.9 m' => [1.9];
        yield 'ceiling at 2.0 m' => [2.0];
        yield 'ceiling at 2.2 m' => [2.2];
    }

    #[DataProvider('ceilings')]
    public function testABoxStepUnderABoxCeilingStaysAWall(float $ceiling): void
    {
        $this->box(new Vec3(10.0, 0.25, 0.0), new Vec3(20.0, 0.5, 40.0));
        $this->box(new Vec3(10.0, $ceiling + 0.25, 0.0), new Vec3(20.2, 0.5, 40.0));

        $trace = $this->walk(60.0, 2.0);

        $rest = array_slice($trace, 60);
        $this->assertEqualsWithDelta(0.0, max($rest) - min($rest), 1e-6, 'trembling under the ceiling');
        $this->assertEqualsWithDelta(0.0, $this->feet(), 1e-6);
        $this->assertLessThanOrEqual(-self::RADIUS + 0.01, $this->player->position->x);
    }

    public function testARotatedBoxStepUnderACeilingStaysAWall(): void
    {
        $this->box(new Vec3(1.5, 0.25, 0.0), new Vec3(3.0, 0.5, 40.0), 0.2);
        $this->box(new Vec3(10.0, 2.25, 0.0), new Vec3(30.0, 0.5, 40.0));

        $trace = $this->walk(60.0, 2.0);

        $rest = array_slice($trace, 60);
        $this->assertEqualsWithDelta(0.0, max($rest) - min($rest), 0.01, 'trembling under the ceiling');
        $this->assertLessThan(0.1, $this->feet());
    }

    public function testABoxStepWithRoomAboveIsStillClimbed(): void
    {
        $this->box(new Vec3(10.0, 0.25, 0.0), new Vec3(20.0, 0.5, 40.0));
        $this->box(new Vec3(10.0, 3.25, 0.0), new Vec3(20.2, 0.5, 40.0)); // 2.5 m above the tread

        $this->walk(60.0, 1.0);

        $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6);
        $this->assertGreaterThan(0.5, $this->player->position->x);
    }

    public function testBoxStairsAreNotTheirOwnHeadroom(): void
    {
        // The next step is inside the capsule's footprint when the lift happens.
        for ($i = 0; $i < 6; $i++) {
            $top = 0.25 * ($i + 1);
            $this->box(new Vec3($i * 0.3 + 0.15, $top / 2.0, 0.0), new Vec3(0.3, $top, 40.0));
        }

        $trace = $this->walk(60.0, 1.0);

        $this->assertEqualsWithDelta(1.5, max($trace), 1e-6);
    }

    public function testWalkingAlongASideWallDoesNotCostTheBoxStep(): void
    {
        $this->box(new Vec3(0.0, 2.0, self::RADIUS + 0.5), new Vec3(40.0, 4.0, 1.0));
        $this->box(new Vec3(10.0, 0.25, -10.0), new Vec3(20.0, 0.5, 20.0));

        $this->walk(60.0, 1.0);

        $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function box(Vec3 $center, Vec3 $size, float $yaw = 0.0): BoxCollider3D
    {
        $collider = new BoxCollider3D(size: $size, isStatic: true);
        $this->world->createEntity()
            ->attach(new Transform3D(position: $center, rotation: Quaternion::fromEuler(0.0, $yaw, 0.0)))
            ->attach($collider);

        return $collider;
    }

    private function feet(): float
    {
        return $this->player->position->y - self::HEIGHT / 2.0;
    }

    /** Moves by position, as games do: @return list<float> feet height per frame */
    private function walk(float $hz, float $seconds): array
    {
        $dt = 1.0 / $hz;
        $trace = [];
        for ($i = 0, $n = (int) ($seconds * $hz); $i < $n; $i++) {
            $this->player->position = $this->player->position->add(new Vec3(self::WALK * $dt, 0.0, 0.0));
            $this->world->update($dt);
            $trace[] = $this->feet();
        }

        return $trace;
    }
}
