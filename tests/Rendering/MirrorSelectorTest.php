<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetPlanarMirror;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\Reflection\MirrorSelector;
use PHPolygon\Rendering\RenderLayers;

final class MirrorSelectorTest extends TestCase
{
    private static function mirror(string $id, Vec3 $center, Vec3 $normal, float $maxDistance = 0.0, float $half = 1.0): SetPlanarMirror
    {
        return new SetPlanarMirror($center, $normal, new Vec3(1.0, 0.0, 0.0), $half, $half, 0.5, $maxDistance, 1, RenderLayers::MIRROR_DEFAULT, 0.0, $id);
    }

    /** @return list<array{0: float, 1: float, 2: float, 3: float}> */
    private static function planes(Vec3 $eye, Vec3 $target): array
    {
        return Frustum::planes(Mat4::perspective(deg2rad(60.0), 16.0 / 9.0, 0.1, 200.0), Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)));
    }

    public function testTheCommandOrthogonalisesAndNormalises(): void
    {
        $m = new SetPlanarMirror(new Vec3(0, 0, 0), new Vec3(0, 0, 3), new Vec3(1, 0, 1), 1.0, 1.0);
        self::assertEqualsWithDelta(1.0, $m->normal->length(), 1e-9);
        self::assertEqualsWithDelta(1.0, $m->right->length(), 1e-9);
        self::assertEqualsWithDelta(0.0, $m->right->dot($m->normal), 1e-9);
        // Up completes a right-handed frame.
        self::assertEqualsWithDelta(1.0, $m->up()->length(), 1e-9);
        self::assertEqualsWithDelta(0.0, $m->up()->dot($m->normal), 1e-9);
    }

    public function testAMirrorSeenFromBehindIsNotChosen(): void
    {
        $eye = new Vec3(0.0, 1.6, 4.0);
        $planes = self::planes($eye, new Vec3(0.0, 1.0, -3.0));
        $front = self::mirror('front', new Vec3(0.0, 1.0, -3.0), new Vec3(0, 0, 1));
        $back = self::mirror('back', new Vec3(0.0, 1.0, -3.5), new Vec3(0, 0, -1));
        self::assertSame($front, MirrorSelector::choose([$back, $front], $eye, $planes));
        self::assertNull(MirrorSelector::choose([$back], $eye, $planes));
    }

    public function testAMirrorOutsideTheFrustumOrBeyondItsDistanceIsNotChosen(): void
    {
        $eye = new Vec3(0.0, 1.6, 4.0);
        $planes = self::planes($eye, new Vec3(0.0, 1.0, -3.0));
        $behindCamera = self::mirror('behind', new Vec3(0.0, 1.0, 12.0), new Vec3(0, 0, -1));
        // Faces the eye, but lies behind the camera: out of the frustum.
        self::assertNull(MirrorSelector::choose([$behindCamera], $eye, $planes));
        self::assertSame($behindCamera, MirrorSelector::choose([$behindCamera], $eye, null), 'without planes nothing is culled');

        $far = self::mirror('far', new Vec3(0.0, 1.0, -30.0), new Vec3(0, 0, 1), 12.0);
        self::assertNull(MirrorSelector::choose([$far], $eye, $planes), 'beyond its own distance limit');
        $unlimited = self::mirror('unlimited', new Vec3(0.0, 1.0, -30.0), new Vec3(0, 0, 1), 0.0, 3.0);
        self::assertSame($unlimited, MirrorSelector::choose([$unlimited], $eye, $planes));
    }

    public function testTheMirrorCoveringMostOfTheViewWins(): void
    {
        $eye = new Vec3(0.0, 1.6, 4.0);
        $planes = self::planes($eye, new Vec3(0.0, 1.0, -3.0));
        $small = self::mirror('small', new Vec3(0.0, 1.0, -3.0), new Vec3(0, 0, 1), 0.0, 0.5);
        $near = self::mirror('near', new Vec3(1.0, 1.0, 0.0), new Vec3(0, 0, 1), 0.0, 0.5);
        $big = self::mirror('big', new Vec3(-1.0, 1.0, -3.0), new Vec3(0, 0, 1), 0.0, 2.0);
        self::assertSame($near, MirrorSelector::choose([$small, $near], $eye, $planes), 'the closer of two equal mirrors');
        self::assertSame($big, MirrorSelector::choose([$small, $big], $eye, $planes), 'the bigger of two at one distance');
        self::assertNull(MirrorSelector::choose([], $eye, $planes));
    }
}
