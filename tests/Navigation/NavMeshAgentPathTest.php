<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\NavMeshAgent;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\System\NavigationSystem;
use PHPolygon\System\Physics3DSystem;

/**
 * Authored waypoint paths without a NavMesh: arrival, facing, wait points and
 * movement through a character controller.
 */
final class NavMeshAgentPathTest extends TestCase
{
    private const float DT = 1.0 / 60.0;

    /** @return array{0: World, 1: NavMeshAgent, 2: Transform3D} */
    private function walker(Vec3 $start, NavMeshAgent $agent): array
    {
        $world = new World();
        $transform = new Transform3D(position: $start);
        $world->createEntity()->attach($transform)->attach($agent);
        $world->addSystem(new NavigationSystem());
        return [$world, $agent, $transform];
    }

    private static function simulate(World $world, float $seconds): void
    {
        for ($i = 0, $n = (int) round($seconds / self::DT); $i < $n; $i++) {
            $world->update(self::DT);
        }
    }

    private static function forward(Transform3D $t): Vec3
    {
        return $t->rotation->rotateVec3(new Vec3(0.0, 0.0, 1.0));
    }

    public function testFollowsAnAuthoredPathWithoutANavMesh(): void
    {
        [$world, $agent, $t] = $this->walker(Vec3::zero(), new NavMeshAgent(speed: 1.3, acceleration: 20.0, stoppingDistance: 0.1));
        $agent->followPath([new Vec3(0, 0, 0), new Vec3(0, 0, 6), new Vec3(8, 0, 6)]);

        self::simulate($world, 5.0);
        $this->assertFalse($agent->hasArrived());
        // 14 m at 1.3 m/s, then the slow-down inside the last 1.3 m.
        self::simulate($world, 10.0);
        $this->assertTrue($agent->hasArrived());
        $this->assertTrue($agent->hasReachedDestination());
        $this->assertLessThanOrEqual(0.1, sqrt($t->position->distanceSquaredTo(new Vec3(8, 0, 6))));
        // No face target and faceMovement off: the rotation is left alone.
        $this->assertTrue($t->rotation->equals(Quaternion::identity()));
    }

    public function testCornerIsPassedNotCut(): void
    {
        [$world, $agent, $t] = $this->walker(Vec3::zero(), new NavMeshAgent(speed: 1.3, acceleration: 50.0, stoppingDistance: 0.1));
        $agent->followPath([new Vec3(0, 0, 6), new Vec3(8, 0, 6)]);
        $maxXBeforeCorner = 0.0;
        for ($i = 0; $i < 300; $i++) {
            $world->update(self::DT);
            if ($t->position->z < 5.8) {
                $maxXBeforeCorner = max($maxXBeforeCorner, $t->position->x);
            }
        }
        $this->assertLessThan(0.05, $maxXBeforeCorner);
    }

    public function testStandsOutAWaitPoint(): void
    {
        [$world, $agent, $t] = $this->walker(Vec3::zero(), new NavMeshAgent(speed: 2.0, acceleration: 50.0, stoppingDistance: 0.05));
        $agent->followPath([new Vec3(0, 0, 4), new Vec3(0, 0, 8)], [0 => 1.5]);

        $reached = null;
        for ($i = 0; $i < 600 && $reached === null; $i++) {
            $world->update(self::DT);
            if ($agent->isWaiting()) {
                $reached = $i;
            }
        }
        $this->assertNotNull($reached);
        $at = $t->position;
        $this->assertEqualsWithDelta(4.0, $at->z, 0.06);
        self::simulate($world, 1.4);
        $this->assertTrue($agent->isWaiting());
        $this->assertTrue($at->equals($t->position));
        self::simulate($world, 8.0);
        $this->assertFalse($agent->isWaiting());
        $this->assertTrue($agent->hasArrived());
        $this->assertEqualsWithDelta(8.0, $t->position->z, 0.06);
    }

    public function testTurnsTowardTheFaceTargetBeforeItCountsAsArrived(): void
    {
        [$world, $agent, $t] = $this->walker(Vec3::zero(), new NavMeshAgent(speed: 2.0, angularSpeed: 90.0, acceleration: 50.0, stoppingDistance: 0.05));
        $agent->faceMovement = true;
        // Walks along +Z, then has to turn a quarter to face +X.
        $agent->followPath([new Vec3(0, 0, 3)], faceTarget: new Vec3(10, 0, 3));

        self::simulate($world, 1.0);
        $f = self::forward($t);
        $this->assertEqualsWithDelta(1.0, $f->z, 1e-6, 'faces its travel direction');

        $arrivedAt = null;
        for ($i = 0; $i < 300 && $arrivedAt === null; $i++) {
            $world->update(self::DT);
            if ($agent->hasArrived()) {
                $arrivedAt = $i;
            }
        }
        $this->assertNotNull($arrivedAt);
        // It stopped up to 5 cm short of z = 3: the target lies a few mrad off +X.
        $f = self::forward($t);
        $this->assertEqualsWithDelta(1.0, $f->x, 1e-4);
        $this->assertEqualsWithDelta(0.0, $f->y, 1e-9);
    }

    public function testYawOffsetForModelsLookingAlongMinusZ(): void
    {
        [$world, $agent, $t] = $this->walker(Vec3::zero(), new NavMeshAgent(speed: 2.0, angularSpeed: 0.0, acceleration: 50.0));
        $agent->faceMovement = true;
        $agent->yawOffset = M_PI;
        $agent->followPath([new Vec3(5, 0, 0)]);
        self::simulate($world, 0.5);

        $back = $t->rotation->rotateVec3(new Vec3(0.0, 0.0, -1.0));
        $this->assertEqualsWithDelta(1.0, $back->x, 1e-6);
    }

    public function testHeightIsLeftToTheControllerAndWallsStopTheWalker(): void
    {
        $world = new World();
        $world->addSystem(new NavigationSystem());
        $world->addSystem(new Physics3DSystem(new Vec3(0.0, -20.0, 0.0), groundPlaneY: -125.0));
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, -0.5, 0.0)))
            ->attach(new BoxCollider3D(size: new Vec3(60.0, 1.0, 60.0), isStatic: true));
        // A wall across the path at z = 4.
        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, 1.5, 4.0)))
            ->attach(new BoxCollider3D(size: new Vec3(6.0, 3.0, 0.2), isStatic: true));

        $t = new Transform3D(position: new Vec3(0.0, 0.9, 0.0));
        $controller = new CharacterController3D(height: 1.8, radius: 0.3);
        $controller->isGrounded = true;
        $agent = new NavMeshAgent(speed: 1.5, acceleration: 20.0);
        $agent->useCharacterController = true;
        $world->createEntity()->attach($t)->attach($controller)->attach($agent);
        // Waypoints authored at another height: only their XZ counts.
        $agent->followPath([new Vec3(0.0, 3.0, 8.0)]);

        self::simulate($world, 6.0);
        $this->assertEqualsWithDelta(0.9, $t->position->y, 0.02);
        $this->assertLessThan(4.0 - 0.1 - 0.3 + 0.02, $t->position->z);
        $this->assertFalse($agent->hasArrived());
    }

    public function testWithoutControllerFlagThePositionIsWrittenDirectly(): void
    {
        [$world, $agent, $t] = $this->walker(Vec3::zero(), new NavMeshAgent(speed: 2.0, acceleration: 50.0, stoppingDistance: 0.05));
        $agent->followPath([new Vec3(0, 2, 4)]);
        self::simulate($world, 8.0);
        $this->assertEqualsWithDelta(2.0, $t->position->y, 0.06);
    }

    public function testPendingDestinationWithoutNavMeshStaysPut(): void
    {
        [$world, $agent, $t] = $this->walker(Vec3::zero(), new NavMeshAgent());
        $agent->setDestination(new Vec3(5, 0, 5));
        self::simulate($world, 1.0);
        $this->assertTrue($t->position->equals(Vec3::zero()));
        $this->assertTrue($agent->isPathPending);
    }

    public function testSameStepsGiveTheSamePath(): void
    {
        $out = [];
        for ($run = 0; $run < 2; $run++) {
            [$world, $agent, $t] = $this->walker(new Vec3(1, 0, 1), new NavMeshAgent(speed: 1.3, angularSpeed: 200.0, acceleration: 6.0, stoppingDistance: 0.2));
            $agent->faceMovement = true;
            $agent->followPath([new Vec3(1, 0, 5), new Vec3(6, 0, 5), new Vec3(6, 0, 9)], [1 => 0.7], new Vec3(9, 0, 9));
            $trace = [];
            for ($i = 0; $i < 900; $i++) {
                $world->update(self::DT);
                if ($i % 50 === 0) {
                    $trace[] = [$t->position->toArray(), $t->rotation->toArray()];
                }
            }
            $out[] = $trace;
        }
        $this->assertSame($out[0], $out[1]);
    }
}
