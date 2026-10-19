<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene\Layout;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\Layout\MountLine;
use PHPolygon\Scene\Layout\MountPoint;
use PHPolygon\Scene\Layout\Opening;
use PHPolygon\Scene\Layout\Zone;
use PHPUnit\Framework\TestCase;

final class LayoutSocketsTest extends TestCase
{
    /** A room placed at (100, 2, -50), turned half way round. */
    private static function halfTurn(): Mat4
    {
        return Mat4::trs(new Vec3(100.0, 2.0, -50.0), Quaternion::fromEuler(0.0, M_PI, 0.0), Vec3::one());
    }

    private static function assertVec(Vec3 $expected, Vec3 $actual, string $message = ''): void
    {
        self::assertEqualsWithDelta($expected->x, $actual->x, 1e-6, $message . ' (x)');
        self::assertEqualsWithDelta($expected->y, $actual->y, 1e-6, $message . ' (y)');
        self::assertEqualsWithDelta($expected->z, $actual->z, 1e-6, $message . ' (z)');
    }

    public function testZoneSortsItsCorners(): void
    {
        $zone = new Zone('walk', new Vec3(2.0, 3.0, -1.0), new Vec3(-2.0, 0.0, 1.0), 'walk');

        self::assertVec(new Vec3(-2.0, 0.0, -1.0), $zone->min);
        self::assertVec(new Vec3(2.0, 3.0, 1.0), $zone->max);
        self::assertVec(new Vec3(0.0, 1.5, 0.0), $zone->center());
        self::assertVec(new Vec3(4.0, 3.0, 2.0), $zone->size());
        self::assertSame('walk', $zone->kind);
    }

    public function testZoneContainmentAndOverlap(): void
    {
        $a = Zone::around('a', new Vec3(0.0, 1.0, 0.0), new Vec3(1.0, 1.0, 1.0));
        $touching = Zone::around('b', new Vec3(2.0, 1.0, 0.0), new Vec3(1.0, 1.0, 1.0));
        $inside = Zone::around('c', new Vec3(0.5, 1.0, 0.5), new Vec3(1.0, 1.0, 1.0));
        $above = Zone::around('d', new Vec3(0.0, 5.0, 0.0), new Vec3(1.0, 1.0, 1.0));

        self::assertTrue($a->contains(new Vec3(1.0, 2.0, -1.0)), 'the boundary belongs to the zone');
        self::assertFalse($a->contains(new Vec3(0.0, 2.5, 0.0)));
        self::assertTrue($a->containsXZ(new Vec3(0.0, 99.0, 0.0)), 'floor-plan test ignores height');

        self::assertFalse($a->overlaps($touching), 'zones meeting at a face are neighbours');
        self::assertTrue($a->overlaps($inside));
        self::assertFalse($a->overlaps($above));
        self::assertTrue($a->overlapsXZ($above), 'floor-plan overlap ignores height');

        $grown = $a->expanded(0.5);
        self::assertVec(new Vec3(-1.5, -0.5, -1.5), $grown->min);
        self::assertTrue($grown->overlaps($touching));
    }

    public function testZoneFollowsAQuarterTurnExactly(): void
    {
        $zone = new Zone('keep', new Vec3(-1.0, 0.0, 8.0), new Vec3(1.0, 2.5, 10.0), 'keep_out');
        $world = $zone->transformed(self::halfTurn());

        self::assertVec(new Vec3(99.0, 2.0, -60.0), $world->min);
        self::assertVec(new Vec3(101.0, 4.5, -58.0), $world->max);
        self::assertSame('keep', $world->name);
        self::assertSame('keep_out', $world->kind);
    }

    public function testMountPointNormalisesAndTransforms(): void
    {
        $mount = new MountPoint('lamp_0', new Vec3(-5.0, 4.75, -5.0), new Vec3(0.0, -3.0, 0.0), 'light');

        self::assertVec(new Vec3(0.0, -1.0, 0.0), $mount->normal);
        self::assertVec(new Vec3(-5.0, 4.5, -5.0), $mount->offset(0.25));

        $world = $mount->transformed(self::halfTurn());
        self::assertVec(new Vec3(105.0, 6.75, -45.0), $world->position);
        self::assertVec(new Vec3(0.0, -1.0, 0.0), $world->normal);
        self::assertSame('light', $world->kind);
    }

    public function testMountLineWalksAlongItsRun(): void
    {
        $line = new MountLine('rail', new Vec3(-4.0, 2.0, -9.5), new Vec3(4.0, 2.0, -9.5), new Vec3(0.0, 0.0, 1.0), 'screen');

        self::assertEqualsWithDelta(8.0, $line->length(), 1e-9);
        self::assertVec(new Vec3(1.0, 0.0, 0.0), $line->direction());
        self::assertVec(new Vec3(0.0, 2.0, -9.5), $line->midpoint());
        self::assertVec(new Vec3(-1.0, 2.0, -9.5), $line->pointAtDistance(3.0));
        self::assertVec(new Vec3(4.0, 2.0, -9.5), $line->pointAt(7.0), 'clamped to the end');

        $world = $line->transformed(self::halfTurn());
        self::assertVec(new Vec3(104.0, 4.0, -40.5), $world->start);
        self::assertVec(new Vec3(0.0, 0.0, -1.0), $world->normal, 'still faces into the room');
    }

    public function testOpeningPublishesItsEdgesAndClearance(): void
    {
        $door = new Opening('inner_door', new Vec3(0.0, 0.0, 10.0), new Vec3(0.0, 0.0, 1.0), 2.0, 2.6, 'door');

        self::assertVec(new Vec3(1.0, 0.0, 0.0), $door->across());
        self::assertVec(new Vec3(0.0, 1.3, 10.0), $door->middle());
        self::assertVec(new Vec3(0.0, 2.6, 10.0), $door->top());

        $clear = $door->clearance(1.2);
        self::assertVec(new Vec3(-1.0, 0.0, 8.8), $clear->min);
        self::assertVec(new Vec3(1.0, 2.6, 11.2), $clear->max);
        self::assertSame('keep_out', $clear->kind);

        $world = $door->transformed(self::halfTurn());
        self::assertVec(new Vec3(100.0, 2.0, -60.0), $world->center);
        self::assertVec(new Vec3(0.0, 0.0, -1.0), $world->normal);
        self::assertVec(new Vec3(0.0, 1.0, 0.0), $world->up);
        self::assertEqualsWithDelta(2.0, $world->width, 1e-9);
    }

    public function testDegenerateSocketsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MountPoint('bad', Vec3::zero(), Vec3::zero());
    }

    public function testOpeningNeedsASize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Opening('bad', Vec3::zero(), new Vec3(0.0, 0.0, 1.0), 0.0, 2.0);
    }
}
