<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Reflection\MirrorPlane;
use PHPolygon\Rendering\Reflection\PlanarReflection;

final class MirrorPlaneTest extends TestCase
{
    public function testReflectionMapsAPointToItsMirrorImageAndIsAnInvolution(): void
    {
        $point = new Vec3(1.0, 2.0, -3.0);
        $normal = (new Vec3(1.0, 2.0, 0.5))->normalize();
        $r = MirrorPlane::reflection($point, $normal);

        // A point on the plane stays.
        $on = $r->transformPoint($point);
        self::assertEqualsWithDelta(1.0, $on->x, 1e-9);
        self::assertEqualsWithDelta(2.0, $on->y, 1e-9);
        self::assertEqualsWithDelta(-3.0, $on->z, 1e-9);

        // Any other point lands at p - 2 (n.(p - c)) n and comes back through the same matrix.
        $p = new Vec3(4.0, -1.0, 2.0);
        $expected = $p->sub($normal->mul(2.0 * $normal->dot($p->sub($point))));
        $q = $r->transformPoint($p);
        self::assertEqualsWithDelta($expected->x, $q->x, 1e-9);
        self::assertEqualsWithDelta($expected->y, $q->y, 1e-9);
        self::assertEqualsWithDelta($expected->z, $q->z, 1e-9);
        $back = $r->transformPoint($q);
        self::assertEqualsWithDelta($p->x, $back->x, 1e-9);
        self::assertEqualsWithDelta($p->y, $back->y, 1e-9);
        self::assertEqualsWithDelta($p->z, $back->z, 1e-9);
    }

    public function testAHorizontalPlaneGivesTheMatrixOfThePlanarReflection(): void
    {
        $view = Mat4::lookAt(new Vec3(3.0, 10.0, 20.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $general = MirrorPlane::mirroredView($view, new Vec3(0.0, -0.4, 0.0), new Vec3(0.0, 1.0, 0.0))->toArray();
        $water = PlanarReflection::mirroredView($view, -0.4)->toArray();
        foreach ($water as $i => $v) {
            self::assertEqualsWithDelta($v, $general[$i], 1e-9, "element {$i}");
        }
        $eye = MirrorPlane::mirroredEye(new Vec3(3.0, 10.0, 20.0), new Vec3(0.0, -0.4, 0.0), new Vec3(0.0, 1.0, 0.0));
        self::assertEqualsWithDelta(-10.8, $eye->y, 1e-9);
        self::assertEqualsWithDelta(3.0, $eye->x, 1e-9);
    }

    public function testTheMirroredViewSeesTheImageOfAWallMirror(): void
    {
        // A mirror in the plane z = -3 facing +z; the camera at z = 4.
        $center = new Vec3(0.0, 1.0, -3.0);
        $normal = new Vec3(0.0, 0.0, 1.0);
        $view = Mat4::lookAt(new Vec3(0.5, 1.6, 4.0), new Vec3(0.0, 1.0, -3.0), new Vec3(0.0, 1.0, 0.0));
        $mirrored = MirrorPlane::mirroredView($view, $center, $normal);
        $p = new Vec3(1.0, 0.5, 1.0);
        $image = new Vec3(1.0, 0.5, -7.0);
        $a = $mirrored->transformPoint($p);
        $b = $view->transformPoint($image);
        self::assertEqualsWithDelta($b->x, $a->x, 1e-9);
        self::assertEqualsWithDelta($b->y, $a->y, 1e-9);
        self::assertEqualsWithDelta($b->z, $a->z, 1e-9);
    }

    public function testTheObliqueNearPlaneCutsAtAWallMirror(): void
    {
        $center = new Vec3(0.0, 1.0, -3.0);
        $normal = new Vec3(0.0, 0.0, 1.0);
        $eye = new Vec3(0.5, 1.6, 4.0);
        $view = Mat4::lookAt($eye, new Vec3(0.0, 1.0, -3.0), new Vec3(0.0, 1.0, 0.0));
        $projection = Mat4::perspective(deg2rad(60.0), 16.0 / 9.0, 0.1, 100.0);
        $mirrored = MirrorPlane::mirroredView($view, $center, $normal);
        $oblique = MirrorPlane::obliqueProjection($projection, $mirrored, $center, $normal);
        $clipVp = $oblique->multiply($mirrored);

        // In front of the mirror (z > -3): the content that gets mirrored stays.
        $front = $clipVp->multiplyVec4(new Vec4(0.0, 1.0, 0.0, 1.0));
        self::assertGreaterThan(-$front->w, $front->z);
        self::assertLessThan($front->w, $front->z);
        // Behind the wall (z < -3): clipped.
        $behind = $clipVp->multiplyVec4(new Vec4(0.0, 1.0, -6.0, 1.0));
        self::assertLessThan(-$behind->w, $behind->z);
        // On the plane: on the near plane.
        $on = $clipVp->multiplyVec4(new Vec4(1.0, 1.0, -3.0, 1.0));
        self::assertEqualsWithDelta(-$on->w, $on->z, 1e-6 * max(1.0, abs($on->w)));
        // x, y, w stay those of the plain projection.
        $plain = $projection->multiply($mirrored)->multiplyVec4(new Vec4(0.0, 1.0, 0.0, 1.0));
        self::assertEqualsWithDelta($plain->x, $front->x, 1e-9);
        self::assertEqualsWithDelta($plain->y, $front->y, 1e-9);
        self::assertEqualsWithDelta($plain->w, $front->w, 1e-9);
    }

    public function testTheClipBiasMovesThePlaneTowardTheViewers(): void
    {
        $center = new Vec3(0.0, 1.0, -3.0);
        $normal = new Vec3(0.0, 0.0, 1.0);
        $view = Mat4::lookAt(new Vec3(0.5, 1.6, 4.0), new Vec3(0.0, 1.0, -3.0), new Vec3(0.0, 1.0, 0.0));
        $projection = Mat4::perspective(deg2rad(60.0), 16.0 / 9.0, 0.1, 100.0);
        $mirrored = MirrorPlane::mirroredView($view, $center, $normal);
        $clipVp = MirrorPlane::obliqueProjection($projection, $mirrored, $center, $normal, 0.05)->multiply($mirrored);

        // A sliver 2 cm in front of the mirror lies inside the biased clip plane; 8 cm does not.
        $sliver = $clipVp->multiplyVec4(new Vec4(0.0, 1.0, -2.98, 1.0));
        self::assertLessThan(-$sliver->w, $sliver->z, 'inside the bias: clipped');
        $clear = $clipVp->multiplyVec4(new Vec4(0.0, 1.0, -2.92, 1.0));
        self::assertGreaterThan(-$clear->w, $clear->z, 'beyond the bias: kept');
    }

    public function testAProjectionThePlaneDoesNotFaceComesBackUnchanged(): void
    {
        // Camera behind the mirror: nothing to reflect.
        $center = new Vec3(0.0, 1.0, -3.0);
        $normal = new Vec3(0.0, 0.0, 1.0);
        $view = Mat4::lookAt(new Vec3(0.0, 1.0, -8.0), new Vec3(0.0, 1.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $projection = Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0);
        $mirrored = MirrorPlane::mirroredView($view, $center, $normal);
        $same = MirrorPlane::obliqueProjection($projection, $mirrored, $center, $normal);
        self::assertSame($projection->toArray(), $same->toArray());
    }
}
