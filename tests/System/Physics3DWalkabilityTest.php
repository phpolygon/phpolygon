<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Physics\BVH;
use PHPolygon\Physics\OrientedBox;
use PHPolygon\Physics\Triangle;
use PHPolygon\System\Physics3DSystem;

/**
 * Walking cases that depend on several parts of the character physics at once:
 * side walls at box steps, solid rotated boxes, slopes, stairs, walking speed
 * over steps and beams below head height.
 */
final class Physics3DWalkabilityTest extends TestCase
{
    private const float HEIGHT = 1.8;

    private World $world;
    private Transform3D $player;
    private CharacterController3D $controller;

    // ─── A box step beside a wall ────────────────────────────────────────

    /** @return iterable<string, array{string, float}> */
    public static function sideWalls(): iterable
    {
        foreach (['box before', 'box after', 'mesh'] as $wall) {
            foreach ([30.0, 60.0, 144.0] as $hz) {
                yield "{$wall}, {$hz} Hz" => [$wall, $hz];
            }
        }
    }

    /** Pressing diagonally into the side wall must not turn the step into a wall. */
    #[DataProvider('sideWalls')]
    public function testABoxStepBesideASideWallIsStillAStep(string $wall, float $hz): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh(self::floor(-20.0, 20.0, 0.0));
        if ($wall === 'box before') {
            $this->box(new Vec3(0.0, 2.0, 1.0), new Vec3(40.0, 4.0, 0.4));
        } elseif ($wall === 'mesh') {
            $this->mesh(self::wallZ(0.8, 0.0, 4.0));
        }
        $this->box(new Vec3(2.0, 0.1, 0.0), new Vec3(4.0, 0.2, 3.0));
        if ($wall === 'box after') {
            $this->box(new Vec3(0.0, 2.0, 1.0), new Vec3(40.0, 4.0, 0.4));
        }

        for ($i = 0, $n = (int) (1.5 * $hz); $i < $n; $i++) {
            $this->frame($hz, 3.5, 3.5);
        }

        $this->assertEqualsWithDelta(0.2, $this->feet(), 1e-6, 'not on the step');
        $this->assertGreaterThan(0.3, $this->player->position->x, 'stopped at the step');
    }

    public function testAWallRightBehindABoxStepStillBlocks(): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh(self::floor(-20.0, 20.0, 0.0));
        $this->box(new Vec3(2.0, 0.1, 0.0), new Vec3(4.0, 0.2, 40.0));
        $this->box(new Vec3(0.12, 2.0, 0.0), new Vec3(0.2, 4.0, 40.0));

        $trace = [];
        for ($i = 0; $i < 120; $i++) {
            $this->frame(60.0, 3.0);
            $trace[] = $this->feet();
        }

        $this->assertEqualsWithDelta(0.0, max($trace), 1e-6, 'lifted against the wall');
    }

    /** The rungs above lie in the plane of the one being stepped on: they are no wall. */
    public function testALadderOfRotatedRungsIsClimbed(): void
    {
        $this->scene(radius: 0.3, stepHeight: 0.65, start: new Vec3(-1.0, 0.9, 0.0));
        $this->mesh(self::floor(-20.0, 20.0, 0.0));
        for ($k = 0; $k < 6; $k++) {
            $top = 0.3 * ($k + 1);
            $this->box(new Vec3(0.3, $top - 0.125, 0.0), new Vec3(0.1, 0.25, 0.45), deg2rad(17.0));
        }

        $highest = 0.0;
        for ($i = 0; $i < 120; $i++) {
            $this->frame(60.0, 4.8);
            $highest = max($highest, $this->feet());
        }

        $this->assertGreaterThan(0.25, $highest, 'stands at the foot of the ladder');
    }

    // ─── Rotated boxes are solid ─────────────────────────────────────────

    /** @return iterable<string, array{float, float, float}> */
    public static function hitches(): iterable
    {
        foreach ([[0.5, 0.0], [2.0, 0.0], [4.0, 0.0], [0.0, 2.0], [2.0, 2.0], [10.0, 0.0], [30.0, 0.0]] as [$yaw, $roll]) {
            foreach ([0.5, 0.7, 1.2] as $jump) {
                yield "yaw {$yaw}, roll {$roll}, jump {$jump}" => [$yaw, $roll, $jump];
            }
        }
    }

    /** A hitch carries the capsule past a face in one step: it must come out on the near side. */
    #[DataProvider('hitches')]
    public function testAPositionJumpDoesNotTunnelIntoARotatedBox(float $yaw, float $roll, float $jump): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3, start: new Vec3(-3.0, 0.9, 0.0));
        $this->mesh(self::floor(-40.0, 40.0, 0.0));
        $entity = $this->box(new Vec3(3.0, 3.0, 0.0), new Vec3(6.0, 6.0, 30.0), deg2rad($yaw), deg2rad($roll));
        for ($i = 0; $i < 60; $i++) {
            $this->frame(60.0, 5.0);
        }

        $this->player->position = $this->player->position->add(new Vec3($jump, 0.0, 0.0));
        $this->world->update(0.1);
        for ($i = 0; $i < 120; $i++) {
            $this->frame(60.0, 5.0);
        }

        // It may slide along an oblique face, it may not be in the box.
        $position = $this->player->position;
        $solid = OrientedBox::fromCollider($entity->get(BoxCollider3D::class), $entity->get(Transform3D::class)->getWorldMatrix());
        $push = $solid->capsulePush($position->add(new Vec3(0.0, -0.5, 0.0)), $position->add(new Vec3(0.0, 0.5, 0.0)), 0.4);
        $this->assertLessThan(0.1, $push?->length() ?? 0.0, 'inside the box');
    }

    public function testAThickRotatedBoxStopsALowFrameRateWalker(): void
    {
        foreach ([[10.0, 8.0], [15.0, 6.0], [20.0, 5.0], [12.0, 8.5]] as [$hz, $speed]) {
            $this->scene(radius: 0.4, stepHeight: 0.3, start: new Vec3(-3.0, 0.9, 0.0));
            $this->mesh(self::floor(-40.0, 40.0, 0.0));
            $this->box(new Vec3(3.0, 3.0, 0.0), new Vec3(6.0, 6.0, 30.0), deg2rad(2.0));

            for ($i = 0, $n = (int) (3.0 * $hz); $i < $n; $i++) {
                $this->frame($hz, $speed);
            }

            $this->assertLessThan(0.0, $this->player->position->x, "at {$hz} Hz");
        }
    }

    public function testARotatedDeckCarriesAndLandsACharacter(): void
    {
        $this->scene(radius: 0.3, stepHeight: 0.3, start: new Vec3(0.0, 4.0, 0.0));
        $deck = $this->box(new Vec3(0.0, 1.0, 0.0), new Vec3(10.0, 0.4, 10.0), deg2rad(20.0), deg2rad(2.0));

        for ($i = 0; $i < 120; $i++) {
            $this->world->update(1.0 / 60.0);
        }

        $this->assertTrue($this->controller->isGrounded);
        $this->assertSame($deck->id, $this->controller->groundEntityId);
        $this->assertEqualsWithDelta(1.2, $this->feet(), 0.02);
    }

    public function testATiltedBoardIsWalkedWithoutJumpingToItsHighestCorner(): void
    {
        foreach ([[3.0, 4.0, 0.2], [4.0, 3.0, 0.15], [2.0, 5.0, 0.2]] as [$tilt, $length, $thickness]) {
            $this->scene(radius: 0.4, stepHeight: 0.3, start: new Vec3(-3.0, 0.9, 0.0));
            $this->mesh(self::floor(-40.0, 40.0, 0.0));
            $this->box(
                new Vec3($length / 2.0, $thickness / 2.0 + $length / 2.0 * sin(deg2rad($tilt)), 0.0),
                new Vec3($length, $thickness, 6.0),
                0.0,
                deg2rad($tilt),
            );

            $previous = $this->feet();
            $largest = 0.0;
            for ($i = 0; $i < 120; $i++) {
                $this->frame(60.0, 3.0);
                $largest = max($largest, $this->feet() - $previous);
                $previous = $this->feet();
            }

            $this->assertLessThan($thickness + 0.1, $largest, "tilt {$tilt}: lifted to the top of the hull");
        }
    }

    // ─── Slopes ──────────────────────────────────────────────────────────

    /** @return iterable<string, array{float, float, float}> */
    public static function steepRamps(): iterable
    {
        foreach ([46.0, 50.0, 55.0, 59.0] as $deg) {
            foreach ([[0.3, 0.3], [0.4, 0.3], [0.3, 0.65]] as [$radius, $step]) {
                yield "{$deg} degrees, r {$radius}, step {$step}" => [$deg, $radius, $step];
            }
        }
    }

    /** Beyond slopeLimit the assist does not help: what is left is the capsule sliding on the face. */
    #[DataProvider('steepRamps')]
    public function testARampBeyondTheSlopeLimitIsNotWalkedAtFullSpeed(float $deg, float $radius, float $step): void
    {
        $this->scene(radius: $radius, stepHeight: $step);
        $this->ramp($deg);

        for ($i = 0; $i < 60; $i++) {
            $this->frame(60.0, 5.0);
        }

        // Free walking reaches x = 3 after one second; the ramp starts at 0, about 0.34 s in.
        $slid = 5.0 * (1.0 - 0.34) * cos(deg2rad($deg)) ** 2;
        $this->assertLessThan($slid + 0.15, $this->player->position->x);
    }

    public function testARampWithinTheConfiguredSlopeLimitIsWalkedAtFullSpeed(): void
    {
        $this->scene(radius: 0.3, stepHeight: 0.65, slopeLimit: 58.0);
        $this->ramp(55.0);

        for ($i = 0; $i < 60; $i++) {
            $this->frame(60.0, 5.0);
        }

        $this->assertGreaterThan(2.5, $this->player->position->x);
    }

    // ─── Stairs at every frame rate ──────────────────────────────────────

    /** @return iterable<string, array{float, float, float, float}> */
    public static function staircases(): iterable
    {
        foreach ([[0.18, 0.28, 0.4], [0.20, 0.25, 0.4], [0.25, 0.30, 0.4], [0.20, 0.22, 0.3]] as [$rise, $run, $radius]) {
            foreach ([60.0, 144.0, 240.0] as $hz) {
                yield "{$rise}/{$run}, r {$radius}, {$hz} Hz" => [$rise, $run, $radius, $hz];
            }
        }
    }

    #[DataProvider('staircases')]
    public function testMeshStairsDoNotCatchTheCapsule(float $rise, float $run, float $radius, float $hz): void
    {
        $this->scene(radius: $radius, stepHeight: 0.3);
        $this->mesh(self::stairs($rise, $run, 8));

        for ($i = 0, $n = (int) (4.0 * $hz); $i < $n; $i++) {
            $this->frame($hz, 5.0);
        }

        $this->assertEqualsWithDelta(8 * $rise, $this->feet(), 1e-6, 'never reached the top');
        $this->assertGreaterThan(14.0, $this->player->position->x, 'lost too much way on the stairs');
    }

    // ─── Walking speed ───────────────────────────────────────────────────

    /** @return iterable<string, array{float, float}> */
    public static function ledges(): iterable
    {
        foreach ([0.25, 0.5, 0.65] as $edge) {
            foreach ([30.0, 60.0, 144.0] as $hz) {
                yield "{$edge} m, {$hz} Hz" => [$edge, $hz];
            }
        }
    }

    /** The forward part of a lift is not a gift: one second of walking stays five metres. */
    #[DataProvider('ledges')]
    public function testAStepDoesNotMakeTheWalkerFaster(float $edge, float $hz): void
    {
        $this->scene(radius: 0.3, stepHeight: 0.65);
        $this->mesh(self::ledge($edge));

        for ($i = 0, $n = (int) $hz; $i < $n; $i++) {
            $this->frame($hz, 5.0);
        }

        $this->assertEqualsWithDelta($edge, $this->feet(), 1e-6);
        $this->assertEqualsWithDelta(3.0, $this->player->position->x, 0.05);
    }

    public function testAStaircaseDoesNotMakeTheWalkerFaster(): void
    {
        foreach ([60.0, 144.0] as $hz) {
            $this->scene(radius: 0.3, stepHeight: 0.3);
            $this->mesh(self::stairs(0.18, 0.28, 20));

            for ($i = 0, $n = (int) $hz; $i < $n; $i++) {
                $this->frame($hz, 5.0);
            }

            // A lift that just happened is still being paid off.
            $unpaid = $this->controller->stepDebt?->length() ?? 0.0;
            $this->assertLessThanOrEqual(3.05, $this->player->position->x - $unpaid, "at {$hz} Hz");
        }
    }

    public function testTheLiftOnlyGoesAsFarAsTheEdge(): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh(self::ledge(0.25));

        $previous = $this->player->position->x;
        $largest = 0.0;
        for ($i = 0; $i < 60; $i++) {
            $this->frame(60.0, 5.0);
            $largest = max($largest, $this->player->position->x - $previous);
            $previous = $this->player->position->x;
        }

        $this->assertLessThan(0.5, $largest);
    }

    public function testAStopForgivesWhatTheLiftGaveAhead(): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh(self::ledge(0.25));
        while ($this->feet() < 0.2) {
            $this->frame(60.0, 5.0);
        }

        $this->world->update(1.0 / 60.0);
        $x = $this->player->position->x;
        $this->frame(60.0, 5.0);

        $this->assertEqualsWithDelta($x + 5.0 / 60.0, $this->player->position->x, 1e-9);
    }

    // ─── Beams below head height ─────────────────────────────────────────

    /** @return iterable<string, array{float, float, float}> */
    public static function beams(): iterable
    {
        foreach ([1.79, 1.76, 1.74, 1.70, 1.60, 1.50] as $clearance) {
            foreach ([1.0, 3.0, 5.0, 8.5] as $speed) {
                foreach ([60.0, 144.0] as $hz) {
                    yield "{$clearance} m at {$speed} m/s, {$hz} Hz" => [$clearance, $speed, $hz];
                }
            }
        }
    }

    #[DataProvider('beams')]
    public function testABeamBelowHeadHeightStopsAMeshWalker(float $clearance, float $speed, float $hz): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh([...self::floor(-20.0, 20.0, 0.0), ...self::wallX(0.0, $clearance, 4.0), ...self::floor(0.0, 5.0, $clearance)]);

        for ($i = 0, $n = (int) (2.0 * $hz); $i < $n; $i++) {
            $this->frame($hz, $speed);
        }

        $this->assertLessThan(0.0, $this->player->position->x, 'walked under the beam');
        $this->assertGreaterThanOrEqual(-1e-6, $this->feet(), 'pressed into the floor');
    }

    public function testABeamJustAboveTheHeadLetsAWalkerThrough(): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh([...self::floor(-20.0, 20.0, 0.0), ...self::wallX(0.0, 1.81, 4.0), ...self::floor(0.0, 5.0, 1.81)]);

        for ($i = 0; $i < 120; $i++) {
            $this->frame(60.0, 3.0);
        }

        $this->assertGreaterThan(3.0, $this->player->position->x);
    }

    /** @return iterable<string, array{float, float}> */
    public static function boxBeams(): iterable
    {
        foreach ([1.76, 1.74, 1.60] as $clearance) {
            foreach ([3.0, 8.5] as $speed) {
                yield "{$clearance} m at {$speed} m/s" => [$clearance, $speed];
            }
        }
    }

    #[DataProvider('boxBeams')]
    public function testABeamBelowHeadHeightStopsABoxWalker(float $clearance, float $speed): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh(self::floor(-20.0, 20.0, 0.0));
        $this->box(new Vec3(2.5, $clearance + 0.25, 0.0), new Vec3(5.0, 0.5, 40.0));

        for ($i = 0; $i < 120; $i++) {
            $this->frame(60.0, $speed);
        }

        $this->assertLessThan(0.0, $this->player->position->x);
    }

    public function testARotatedBeamBelowHeadHeightStopsAWalker(): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh(self::floor(-20.0, 20.0, 0.0));
        $this->box(new Vec3(2.5, 1.99, 0.0), new Vec3(5.0, 0.5, 40.0), deg2rad(2.0));

        for ($i = 0; $i < 120; $i++) {
            $this->frame(60.0, 3.0);
        }

        $this->assertLessThan(0.0, $this->player->position->x);
    }

    public function testACeilingStillStopsAJump(): void
    {
        $this->scene(radius: 0.4, stepHeight: 0.3);
        $this->mesh([...self::floor(-20.0, 20.0, 0.0), ...self::floor(-20.0, 20.0, 2.5)]);
        $this->world->update(1.0 / 60.0);

        $this->controller->isGrounded = false;
        $this->controller->velocity = new Vec3(0.0, 8.0, 0.0);
        $highest = 0.0;
        for ($i = 0; $i < 60; $i++) {
            $this->world->update(1.0 / 60.0);
            $highest = max($highest, $this->feet());
        }

        $this->assertLessThanOrEqual(0.7 + 1e-6, $highest);
        $this->assertEqualsWithDelta(0.0, $this->feet(), 1e-6);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function scene(float $radius, float $stepHeight, ?Vec3 $start = null, float $slopeLimit = 45.0): void
    {
        $this->world = new World();
        $this->world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -500.0));
        $this->player = new Transform3D(position: $start ?? new Vec3(-2.0, self::HEIGHT / 2.0, 0.0));
        $this->controller = new CharacterController3D(height: self::HEIGHT, radius: $radius, stepHeight: $stepHeight, slopeLimit: $slopeLimit);
        $this->controller->isGrounded = true;
        $this->world->createEntity()->attach($this->player)->attach($this->controller);
    }

    private function feet(): float
    {
        return $this->player->position->y - self::HEIGHT / 2.0;
    }

    private function frame(float $hz, float $speedX, float $speedZ = 0.0): void
    {
        $dt = 1.0 / $hz;
        $this->player->position = $this->player->position->add(new Vec3($speedX * $dt, 0.0, $speedZ * $dt));
        $this->world->update($dt);
    }

    /** @param list<Triangle> $triangles */
    private function mesh(array $triangles): Entity
    {
        $transform = new Transform3D();
        $collider = new MeshCollider3D(meshId: 'test_walk', isStatic: true);
        $collider->bvh = BVH::build($triangles);
        $collider->lastWorldMatrix = $transform->getWorldMatrix();

        return $this->world->createEntity()->attach($transform)->attach($collider);
    }

    private function box(Vec3 $center, Vec3 $size, float $yaw = 0.0, float $roll = 0.0): Entity
    {
        return $this->world->createEntity()
            ->attach(new Transform3D(position: $center, rotation: Quaternion::fromEuler(0.0, $yaw, $roll)))
            ->attach(new BoxCollider3D(size: $size, isStatic: true));
    }

    private function ramp(float $deg): void
    {
        $slope = tan(deg2rad($deg));
        $a = new Vec3(0.0, 0.0, -20.0);
        $b = new Vec3(0.0, 0.0, 20.0);
        $c = new Vec3(10.0, 10.0 * $slope, 20.0);
        $d = new Vec3(10.0, 10.0 * $slope, -20.0);
        $this->mesh([...self::floor(-20.0, 0.0, 0.0), new Triangle($a, $b, $c), new Triangle($a, $c, $d)]);
    }

    /** @return list<Triangle> */
    private static function ledge(float $edge): array
    {
        return [...self::floor(-20.0, 0.0, 0.0), ...self::wallX(0.0, 0.0, $edge), ...self::floor(0.0, 20.0, $edge)];
    }

    /** @return list<Triangle> */
    private static function stairs(float $rise, float $run, int $steps): array
    {
        $tris = self::floor(-20.0, 0.0, 0.0);
        for ($i = 0; $i < $steps; $i++) {
            $y = ($i + 1) * $rise;
            $tris = [...$tris, ...self::wallX($i * $run, $y - $rise, $y), ...self::floor($i * $run, ($i + 1) * $run, $y)];
        }

        return [...$tris, ...self::floor($steps * $run, 30.0, $steps * $rise)];
    }

    /** @return list<Triangle> */
    private static function floor(float $x0, float $x1, float $y): array
    {
        $a = new Vec3($x0, $y, -20.0);
        $b = new Vec3($x1, $y, -20.0);
        $c = new Vec3($x1, $y, 20.0);
        $d = new Vec3($x0, $y, 20.0);

        return [new Triangle($a, $d, $c), new Triangle($a, $c, $b)];
    }

    /** @return list<Triangle> */
    private static function wallX(float $x, float $y0, float $y1): array
    {
        $a = new Vec3($x, $y0, -20.0);
        $b = new Vec3($x, $y0, 20.0);
        $c = new Vec3($x, $y1, 20.0);
        $d = new Vec3($x, $y1, -20.0);

        return [new Triangle($a, $b, $c), new Triangle($a, $c, $d)];
    }

    /** @return list<Triangle> */
    private static function wallZ(float $z, float $y0, float $y1): array
    {
        $a = new Vec3(-20.0, $y0, $z);
        $b = new Vec3(20.0, $y0, $z);
        $c = new Vec3(20.0, $y1, $z);
        $d = new Vec3(-20.0, $y1, $z);

        return [new Triangle($a, $b, $c), new Triangle($a, $c, $d)];
    }
}
