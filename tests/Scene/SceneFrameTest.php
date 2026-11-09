<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\SceneFrame;
use PHPUnit\Framework\TestCase;

final class SceneFrameTest extends TestCase
{
    private static function assertVec(Vec3 $expected, Vec3 $actual, string $message = ''): void
    {
        self::assertEqualsWithDelta([$expected->x, $expected->y, $expected->z], [$actual->x, $actual->y, $actual->z], 1e-9, $message);
    }

    public function testPlacesOnlyWhatWasDeclaredAfterTheMark(): void
    {
        $b = new SceneBuilder();
        $b->entity('Before')->with(new Transform3D(position: new Vec3(1.0, 0.0, 0.0)));
        $frame = new SceneFrame(new Vec3(100.0, 5.0, -20.0), M_PI / 2.0);
        $mark = SceneFrame::mark($b);
        $b->entity('Wall')->with(new Transform3D(position: new Vec3(10.0, 2.0, 0.0)))
            ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));

        self::assertSame(1, $frame->place($b, $mark));
        [$before, $wall] = $b->getDeclarations();
        self::assertVec(new Vec3(1.0, 0.0, 0.0), self::transform($before)->position, 'untouched');
        // +90 degrees about +Y turns +X into -Z.
        self::assertVec(new Vec3(100.0, 7.0, -30.0), self::transform($wall)->position);
    }

    public function testTheEntityCarriesTheTurnItself(): void
    {
        $b = new SceneBuilder();
        $frame = new SceneFrame(Vec3::zero(), 0.3);
        $mark = SceneFrame::mark($b);
        $own = Quaternion::fromEuler(0.0, 0.2, 0.0);
        $b->entity('Beam')->with(new Transform3D(rotation: $own));
        $frame->place($b, $mark);

        $t = self::transform($b->getDeclarations()[0]);
        self::assertTrue($t->rotation->equals(Quaternion::fromEuler(0.0, 0.5, 0.0), 1e-9), 'yaws add up');
        self::assertTrue($t->worldMatrix->toArray() === $t->getLocalMatrix()->toArray(), 'world matrix refreshed');
        self::assertNull($t->parentEntityId, 'no parent: a box collider sees its own rotation');
    }

    public function testChildrenKeepTheirLocalTransform(): void
    {
        $b = new SceneBuilder();
        $frame = new SceneFrame(new Vec3(5.0, 0.0, 0.0), 1.0);
        $mark = SceneFrame::mark($b);
        $parent = $b->entity('Parent')->with(new Transform3D(position: new Vec3(1.0, 0.0, 0.0)));
        $parent->child('Child')->with(new Transform3D(position: new Vec3(0.0, 1.0, 0.0)));
        $frame->place($b, $mark);

        $child = $b->getDeclarations()[0]->getChildren()[0];
        self::assertVec(new Vec3(0.0, 1.0, 0.0), self::transform($child)->position);
    }

    public function testPointsDirectionsAndYawsMatchThePlacedEntities(): void
    {
        $frame = new SceneFrame(new Vec3(-3.0, 1.0, 4.0), 2.0);
        $b = new SceneBuilder();
        $mark = SceneFrame::mark($b);
        $local = new Vec3(2.0, 0.5, -1.0);
        $b->entity('Socket')->with(new Transform3D(position: $local, rotation: Quaternion::fromEuler(0.0, 0.4, 0.0)));
        $frame->place($b, $mark);
        $t = self::transform($b->getDeclarations()[0]);

        self::assertVec($t->position, $frame->point($local));
        self::assertVec($frame->direction(new Vec3(1.0, 0.0, 0.0)), $t->rotation->rotateVec3(Quaternion::fromEuler(0.0, -0.4, 0.0)->rotateVec3(new Vec3(1.0, 0.0, 0.0))));
        self::assertTrue(Quaternion::fromEuler(0.0, $frame->yaw(0.4), 0.0)->equals($t->rotation, 1e-9));
        self::assertVec($local, $frame->toLocal($frame->point($local)), 'toLocal undoes point');
    }

    private static function transform(\PHPolygon\Scene\EntityDeclaration $d): Transform3D
    {
        foreach ($d->getComponents() as $c) {
            if ($c instanceof Transform3D) {
                return $c;
            }
        }
        self::fail('no transform');
    }
}
