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
use PHPolygon\Physics\Triangle;
use PHPolygon\System\Physics3DSystem;

/**
 * CharacterController3D::$stepHeight holds on mesh colliders too, at any frame
 * rate. Mesh collision alone only pushes the capsule along the shortest way out,
 * so an edge blocks however low it is.
 */
final class Physics3DStepAssistTest extends TestCase
{
    private const float STEP_HEIGHT = 0.65;
    private const float WALK = 5.0;
    private const float SPRINT = 8.5;
    private const float HALF = 0.9;
    private const float RADIUS = 0.3;

    private World $world;
    private Transform3D $player;
    private CharacterController3D $controller;
    private Entity $ground;

    // ─── Edges ───────────────────────────────────────────────────────────

    /** @return iterable<string, array{float, float, float}> */
    public static function climbable(): iterable
    {
        foreach ([0.10, 0.30, 0.50, 0.64, 0.65] as $edge) {
            foreach ([30.0, 60.0, 144.0] as $hz) {
                foreach ([self::WALK, self::SPRINT] as $speed) {
                    yield sprintf('%.2f m at %d Hz, %.1f m/s', $edge, $hz, $speed) => [$edge, $hz, $speed];
                }
            }
        }
    }

    #[DataProvider('climbable')]
    public function testAnEdgeWithinTheStepHeightIsWalkedUp(float $edge, float $hz, float $speed): void
    {
        $this->ledge($edge);

        $trace = $this->walk($hz, $speed, 1.5);

        $this->assertGreaterThan(0.5, $this->player->position->x);
        $this->assertEqualsWithDelta($edge, $this->feet(), 1e-6);
        $this->assertTrue($this->controller->isGrounded);
        $previous = -INF;
        foreach ($trace as $i => $y) {
            $this->assertLessThanOrEqual($edge + 1e-6, $y, "frame {$i}: overshoot");
            $this->assertGreaterThanOrEqual($previous - 1e-6, $y, "frame {$i}: drop");
            $previous = $y;
        }
    }

    /** @return iterable<string, array{float, float}> */
    public static function rates(): iterable
    {
        foreach ([30.0, 60.0, 144.0] as $hz) {
            foreach ([self::WALK, self::SPRINT] as $speed) {
                yield sprintf('%d Hz, %.1f m/s', $hz, $speed) => [$hz, $speed];
            }
        }
    }

    #[DataProvider('rates')]
    public function testAnEdgeAboveTheStepHeightStaysAWall(float $hz, float $speed): void
    {
        $this->ledge(0.66);

        $trace = $this->walk($hz, $speed, 1.5);

        $this->assertLessThanOrEqual(-self::RADIUS + 0.01, $this->player->position->x);
        $this->assertEqualsWithDelta(0.0, $this->feet(), 1e-6);
        $this->assertEqualsWithDelta(0.0, max($trace) - min($trace), 1e-6, 'trembling at the wall');
    }

    public function testVeryHighFrameRatesKeepTheAssist(): void
    {
        // 500 Hz carries 0.01 m per frame: intent must be a speed, not a distance.
        foreach ([240.0, 500.0] as $hz) {
            $this->ledge(0.5);
            $this->walk($hz, self::WALK, 1.5);
            $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6, "at {$hz} Hz");
        }
    }

    public function testAnAngledApproachClimbsToo(): void
    {
        foreach ([30.0, 60.0, 144.0] as $hz) {
            $this->ledge(0.5);
            $this->walk($hz, self::WALK, 1.5, deg2rad(20.0));
            $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6, "at {$hz} Hz");
        }
    }

    public function testACharacterMovedByVelocityClimbsToo(): void
    {
        $this->ledge(0.5);

        for ($i = 0; $i < 90; $i++) {
            $this->controller->velocity = new Vec3(self::WALK, $this->controller->velocity->y, 0.0);
            $this->world->update(1.0 / 60.0);
        }

        $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6);
    }

    public function testAStepHeightOfZeroClimbsNothing(): void
    {
        $this->ledge(0.4, stepHeight: 0.0); // above the capsule's round end, which rolls over less

        $this->walk(60.0, self::WALK, 1.0);

        $this->assertLessThan(0.0, $this->player->position->x);
    }

    public function testTheStepHeightOfEachCharacterCounts(): void
    {
        $this->ledge(0.5, stepHeight: 0.4);

        $this->walk(60.0, self::WALK, 1.0);

        $this->assertLessThan(0.0, $this->player->position->x);
    }

    // ─── The ground it hands over ────────────────────────────────────────

    public function testTheLiftNamesTheNewGroundOnItsTick(): void
    {
        $this->scene([...self::quad(-20.0, 0.0, 0.0, -20.0, 20.0), ...self::wallX(0.0, 0.0, 0.5)]);
        $step = $this->addMesh(self::quad(0.0, 20.0, 0.5, -20.0, 20.0)); // the tread is another entity

        for ($i = 0; $i < 90 && $this->feet() < 0.4; $i++) {
            $this->walkFrame(60.0, self::WALK, 0.0);
        }

        $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6);
        $this->assertSame($step->id, $this->controller->groundEntityId);
    }

    // ─── Walls do not creep ──────────────────────────────────────────────

    /**
     * A refused step must not push the capsule up the wall, and the triangles of a
     * wall quad must not matter in their order: the diagonal is no ramp.
     *
     * @return iterable<string, array{bool, float}>
     */
    public static function wallOrders(): iterable
    {
        foreach ([false, true] as $reversed) {
            foreach ([30.0, 60.0, 144.0] as $hz) {
                yield sprintf('%s, %d Hz', $reversed ? 'reversed' : 'as built', $hz) => [$reversed, $hz];
            }
        }
    }

    #[DataProvider('wallOrders')]
    public function testARefusedStepDoesNotCreepUpTheWall(bool $reversed, float $hz): void
    {
        $wall = [...self::wallX(0.0, 0.0, 0.5), ...self::wallX(0.1, 0.5, 3.0)];
        $this->scene([
            ...self::quad(-20.0, 0.0, 0.0, -20.0, 20.0),
            ...($reversed ? array_reverse($wall) : $wall),
            ...self::quad(0.0, 20.0, 0.5, -20.0, 20.0),
        ]);

        $trace = $this->walk($hz, self::WALK, 2.0);

        $this->assertLessThanOrEqual(0.03, max($trace));
        $this->assertLessThanOrEqual(-self::RADIUS + 0.01, $this->player->position->x);
    }

    public function testWalkingIntoAWallAtAnAngleStaysOnTheFloor(): void
    {
        foreach ([0.2, 0.7, 1.2] as $angle) {
            $this->ledge(2.0);
            $trace = $this->walk(30.0, self::WALK, 1.0, $angle);

            $this->assertLessThanOrEqual(1e-6, max($trace), "angle {$angle}");
        }
    }

    // ─── Not in the air ──────────────────────────────────────────────────

    public function testNothingIsClimbedInTheAir(): void
    {
        $this->ledge(0.5);
        $this->controller->isGrounded = false;
        $this->player->position = new Vec3(-0.5, self::HALF + 0.2, 0.0);
        $dt = 1.0 / 60.0;

        // Pressed against the wall in the fall.
        for ($i = 0; $i < 10; $i++) {
            $this->player->position = $this->player->position->add(new Vec3(self::WALK * $dt, 0.0, 0.0));
            $this->world->update($dt);
            if (!$this->controller->isGrounded) {
                $this->assertLessThan(0.3, $this->feet(), "frame {$i}");
            }
        }
    }

    public function testACoupledCharacterIsLeftAlone(): void
    {
        $this->ledge(0.5);
        $seat = $this->world->createEntity()->attach(new Transform3D(position: new Vec3(-0.5, 2.0, 0.0)));
        $this->world->update(1.0 / 60.0);

        $this->controller->coupleTo($seat->id, new Vec3(0.0, 0.0, 0.0));
        $this->world->update(1.0 / 60.0);
        $this->assertEqualsWithDelta(2.0, $this->player->position->y, 1e-9);

        // Leaving a seat is a jump of the position, not a walk into anything.
        $this->controller->decouple();
        $this->world->update(1.0 / 60.0);
        $this->assertEqualsWithDelta(-0.5, $this->player->position->x, 1e-9);
    }

    // ─── Stairs and ramps ────────────────────────────────────────────────

    /** @return iterable<string, array{float, float, float}> */
    public static function stairs(): iterable
    {
        foreach ([[0.16, 0.30], [0.18, 0.28], [0.25, 0.30]] as [$rise, $run]) {
            foreach ([30.0, 60.0, 144.0] as $hz) {
                yield sprintf('%.2f/%.2f at %d Hz', $rise, $run, $hz) => [$rise, $run, $hz];
            }
        }
    }

    #[DataProvider('stairs')]
    public function testAStaircaseIsClimbed(float $rise, float $run, float $hz): void
    {
        $steps = 8;
        $tris = self::quad(-20.0, 0.0, 0.0, -20.0, 20.0);
        for ($i = 0; $i < $steps; $i++) {
            $y = ($i + 1) * $rise;
            $tris = [...$tris, ...self::wallX($i * $run, $y - $rise, $y), ...self::quad($i * $run, ($i + 1) * $run, $y, -20.0, 20.0)];
        }
        $top = $steps * $rise;
        $this->scene([...$tris, ...self::quad($steps * $run, 20.0, $top, -20.0, 20.0)]);

        $trace = $this->walk($hz, self::WALK, 4.0);

        $this->assertEqualsWithDelta($top, $this->feet(), 1e-6);
        $this->assertLessThanOrEqual($top + 1e-6, max($trace), 'shot over the top');
    }

    /** @return iterable<string, array{float, float}> */
    public static function ramps(): iterable
    {
        foreach ([10.0, 20.0, 30.0, 40.0] as $deg) {
            foreach ([30.0, 60.0, 144.0] as $hz) {
                yield sprintf('%d degrees at %d Hz', $deg, $hz) => [$deg, $hz];
            }
        }
    }

    #[DataProvider('ramps')]
    public function testARampIsWalkedWithoutALift(float $deg, float $hz): void
    {
        $slope = tan(deg2rad($deg));
        $a = new Vec3(0.0, 0.0, -20.0);
        $b = new Vec3(0.0, 0.0, 20.0);
        $c = new Vec3(10.0, 10.0 * $slope, 20.0);
        $d = new Vec3(10.0, 10.0 * $slope, -20.0);
        $this->scene([...self::quad(-20.0, 0.0, 0.0, -20.0, 20.0), new Triangle($a, $b, $c), new Triangle($a, $c, $d)]);

        $trace = $this->walk($hz, self::WALK, 1.5);

        // More than a frame's worth of slope (generously) would be a jump of the assist.
        $limit = max(0.02, 3.0 * self::WALK / $hz * $slope);
        for ($i = 1, $n = count($trace); $i < $n; $i++) {
            $this->assertLessThanOrEqual($limit, $trace[$i] - $trace[$i - 1], "frame {$i}");
        }
        $this->assertGreaterThan(1.0, $this->player->position->x);
    }

    // ─── Not through walls, not into ceilings ────────────────────────────

    #[DataProvider('rates')]
    public function testAThinWallKeepsTheProbeFromTheFloorBehindIt(float $hz, float $speed): void
    {
        foreach ([0.10, 0.30, 0.60] as $behind) {
            $this->scene([
                ...self::quad(-20.0, 0.0, 0.0, -20.0, 20.0),
                ...self::quad(0.0, 20.0, $behind, -20.0, 20.0),
                ...self::wallX(0.0, 0.0, 2.0),
            ]);

            $this->walk($hz, $speed, 1.0);

            $this->assertLessThanOrEqual(-self::RADIUS + 0.01, $this->player->position->x, "through the wall ({$behind} m)");
            $this->assertLessThan(0.1, $this->feet(), "onto the floor behind the wall ({$behind} m)");
        }
    }

    #[DataProvider('rates')]
    public function testAWallOnTheTreadKeepsTheWalkerOff(float $hz, float $speed): void
    {
        $this->scene([
            ...self::quad(-20.0, 0.0, 0.0, -20.0, 20.0),
            ...self::wallX(0.0, 0.0, 0.4),
            ...self::quad(0.0, 0.1, 0.4, -20.0, 20.0),
            ...self::wallX(0.1, 0.4, 2.4),
            ...self::quad(0.1, 20.0, 0.4, -20.0, 20.0),
        ]);

        $this->walk($hz, $speed, 1.0);

        $this->assertLessThan(0.1, $this->player->position->x);
    }

    public function testAFenceStopsTheWalkerEvenIfTheGroundRisesBehindIt(): void
    {
        foreach ([0.0, 0.5] as $yaw) {
            $this->scene([...self::quad(-20.0, 0.25, 0.0, -20.0, 20.0), ...self::quad(0.25, 20.0, 0.3, -20.0, 20.0), ...self::wallX(0.25, 0.0, 0.3)]);
            $this->addBox(new Vec3(0.0, 1.05, 0.0), new Vec3(0.4, 3.0, 40.0), $yaw);

            $this->walk(60.0, self::WALK, 1.0);

            $this->assertLessThan(0.0, $this->player->position->x, "through the fence (yaw {$yaw})");
            $this->assertEqualsWithDelta(0.0, $this->feet(), 0.01);
        }
    }

    /** @return iterable<string, array{float}> */
    public static function clearances(): iterable
    {
        yield 'a beam over the tread' => [1.35];
        yield 'just under body height' => [1.75];
    }

    #[DataProvider('clearances')]
    public function testNobodyStepsIntoACeiling(float $clearance): void
    {
        foreach ([30.0, 60.0, 144.0] as $hz) {
            $this->ledge(0.5);
            $this->addMesh(self::underside(-0.05, 20.0, 0.5 + $clearance));

            $this->walk($hz, self::WALK, 1.0);

            $this->assertLessThanOrEqual(-self::RADIUS + 0.01, $this->player->position->x, "at {$hz} Hz");
            $this->assertLessThan(0.5, $this->feet(), "at {$hz} Hz");
        }
    }

    public function testACeilingBelowTheRaisedHeadStopsTheStepBeforeTheEdge(): void
    {
        $this->ledge(0.5);
        $this->addMesh(self::underside(-5.0, 20.0, 2.0));

        $this->walk(60.0, self::WALK, 1.0);

        $this->assertLessThanOrEqual(-self::RADIUS + 0.01, $this->player->position->x);
        $this->assertLessThan(0.5, $this->feet());
    }

    public function testWalkingAlongASideWallDoesNotBlockTheStep(): void
    {
        foreach ([30.0, 60.0, 144.0] as $hz) {
            $a = new Vec3(-20.0, 0.0, self::RADIUS);
            $b = new Vec3(20.0, 0.0, self::RADIUS);
            $c = new Vec3(20.0, 4.0, self::RADIUS);
            $d = new Vec3(-20.0, 4.0, self::RADIUS);
            $this->ledge(0.5, extra: [new Triangle($a, $b, $c), new Triangle($a, $c, $d)]);

            $this->walk($hz, self::WALK, 1.5);

            $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6, "at {$hz} Hz");
        }
    }

    // ─── Rotated boxes ───────────────────────────────────────────────────

    /** A long rotated rail beside the way: its hull covers the edge, the rail does not. */
    public function testARotatedRailBesideTheWayDoesNotBlockTheStep(): void
    {
        foreach ([0.1, -0.1] as $yaw) {
            $this->ledge(0.5);
            $this->addBox(new Vec3(0.0, 0.6, 1.2), new Vec3(38.0, 1.2, 0.1), $yaw);

            $this->walk(60.0, self::WALK, 1.0);

            $this->assertGreaterThan(0.5, $this->player->position->x);
            $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6);
        }
    }

    public function testAStepFromARotatedDeckCountsFromTheDeck(): void
    {
        foreach ([30.0, 60.0, 144.0] as $hz) {
            $this->ledge(0.9);
            $this->addBox(new Vec3(-5.2, 0.3, 0.0), new Vec3(10.0, 0.2, 10.0), 0.1);
            $this->player->position = new Vec3(-3.0, 0.4 + self::HALF, 0.0);

            $this->walk($hz, self::WALK, 1.0);

            $this->assertEqualsWithDelta(0.9, $this->feet(), 1e-6, "at {$hz} Hz");
        }
    }

    public function testARotatedBoxInTheWayStillStopsTheStep(): void
    {
        foreach ([30.0, 60.0, 144.0] as $hz) {
            $this->ledge(0.5);
            $this->addBox(new Vec3(0.3, 1.5, 0.0), new Vec3(0.4, 3.0, 40.0), 0.1);

            $this->walk($hz, self::WALK, 1.0);

            $this->assertLessThan(0.0, $this->player->position->x, "at {$hz} Hz");
            $this->assertLessThan(0.5, $this->feet(), "at {$hz} Hz");
        }
    }

    public function testATriggerIsNeitherTreadNorObstacle(): void
    {
        $this->ledge(0.5);
        $this->world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.3, 1.5, 0.0)))
            ->attach(new BoxCollider3D(size: new Vec3(0.4, 3.0, 40.0), isTrigger: true, isStatic: true));

        $this->walk(60.0, self::WALK, 1.0);

        $this->assertEqualsWithDelta(0.5, $this->feet(), 1e-6);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /** @param list<Triangle> $extra */
    private function ledge(float $edge, float $stepHeight = self::STEP_HEIGHT, array $extra = []): void
    {
        $this->scene([...self::quad(-20.0, 0.0, 0.0, -20.0, 20.0), ...self::wallX(0.0, 0.0, $edge), ...self::quad(0.0, 20.0, $edge, -20.0, 20.0), ...$extra], $stepHeight);
    }

    /** @param list<Triangle> $triangles */
    private function scene(array $triangles, float $stepHeight = self::STEP_HEIGHT): void
    {
        $this->world = new World();
        $this->world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -500.0));
        $this->ground = $this->addMesh($triangles);

        $this->player = new Transform3D(position: new Vec3(-2.0, self::HALF, 0.0));
        $this->controller = new CharacterController3D(height: 1.8, radius: self::RADIUS, stepHeight: $stepHeight);
        $this->controller->isGrounded = true;
        $this->world->createEntity()->attach($this->player)->attach($this->controller);
    }

    /** @param list<Triangle> $triangles */
    private function addMesh(array $triangles): Entity
    {
        $transform = new Transform3D();
        $collider = new MeshCollider3D(meshId: 'test_ledge', isStatic: true);
        $collider->bvh = BVH::build($triangles);
        $collider->lastWorldMatrix = $transform->getWorldMatrix();

        return $this->world->createEntity()->attach($transform)->attach($collider);
    }

    private function addBox(Vec3 $center, Vec3 $size, float $yaw = 0.0): void
    {
        $this->world->createEntity()
            ->attach(new Transform3D(position: $center, rotation: Quaternion::fromEuler(0.0, $yaw, 0.0)))
            ->attach(new BoxCollider3D(size: $size, isStatic: true));
    }

    private function feet(): float
    {
        return $this->player->position->y - self::HALF;
    }

    private function walkFrame(float $hz, float $speed, float $angle): void
    {
        $dt = 1.0 / $hz;
        $this->player->position = $this->player->position->add(new Vec3(cos($angle) * $speed * $dt, 0.0, sin($angle) * $speed * $dt));
        $this->world->update($dt);
    }

    /** @return list<float> feet height per frame */
    private function walk(float $hz, float $speed, float $seconds, float $angle = 0.0): array
    {
        $trace = [];
        for ($i = 0, $n = (int) ($seconds * $hz); $i < $n; $i++) {
            $this->walkFrame($hz, $speed, $angle);
            $trace[] = $this->feet();
            $this->assertLessThan(0.5, $this->controller->velocity->y, "frame {$i}: thrown upward");
        }

        return $trace;
    }

    /** @return list<Triangle> level quad, normal up */
    private static function quad(float $x0, float $x1, float $y, float $z0, float $z1): array
    {
        $a = new Vec3($x0, $y, $z0);
        $b = new Vec3($x1, $y, $z0);
        $c = new Vec3($x1, $y, $z1);
        $d = new Vec3($x0, $y, $z1);

        return [new Triangle($a, $d, $c), new Triangle($a, $c, $b)];
    }

    /** @return list<Triangle> level quad, normal down */
    private static function underside(float $x0, float $x1, float $y): array
    {
        $a = new Vec3($x0, $y, -20.0);
        $b = new Vec3($x1, $y, -20.0);
        $c = new Vec3($x1, $y, 20.0);
        $d = new Vec3($x0, $y, 20.0);

        return [new Triangle($a, $c, $d), new Triangle($a, $b, $c)];
    }

    /** @return list<Triangle> upright quad at x, normal toward -X */
    private static function wallX(float $x, float $y0, float $y1, float $z0 = -20.0, float $z1 = 20.0): array
    {
        $a = new Vec3($x, $y0, $z0);
        $b = new Vec3($x, $y0, $z1);
        $c = new Vec3($x, $y1, $z1);
        $d = new Vec3($x, $y1, $z0);

        return [new Triangle($a, $b, $c), new Triangle($a, $c, $d)];
    }
}
