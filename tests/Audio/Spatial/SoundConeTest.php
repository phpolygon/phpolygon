<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Spatial;

use InvalidArgumentException;
use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\AttenuationCurve;
use PHPolygon\Audio\Spatial\SoundCone;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

final class SoundConeTest extends TestCase
{
    public function testGainInsideBetweenAndOutside(): void
    {
        $cone = SoundCone::degrees(60.0, 120.0, 0.25);
        $facing = new Vec3(0.0, 0.0, -1.0);
        $this->assertSame(1.0, $cone->gain($facing, new Vec3(0.0, 0.0, -3.0)));
        // 29° off axis: still inside the 60° inner cone (±30°).
        $this->assertSame(1.0, $cone->gain($facing, new Vec3(sin(deg2rad(29.0)), 0.0, -cos(deg2rad(29.0)))));
        // 45°: halfway in cosine between cos 30° and cos 60°.
        $d = cos(deg2rad(45.0));
        $expected = 0.25 + 0.75 * ($d - cos(deg2rad(60.0))) / (cos(deg2rad(30.0)) - cos(deg2rad(60.0)));
        $this->assertEqualsWithDelta($expected, $cone->gain($facing, new Vec3(1.0, 0.0, -1.0)), 1e-9);
        $this->assertSame(0.25, $cone->gain($facing, new Vec3(0.0, 0.0, 4.0)));
        // Degenerate vectors and the full circle: no cone.
        $this->assertSame(1.0, $cone->gain(new Vec3(), new Vec3(0.0, 0.0, 4.0)));
        $this->assertSame(1.0, (new SoundCone(SoundCone::FULL_CIRCLE, SoundCone::FULL_CIRCLE, 0.0))->gain($facing, new Vec3(0.0, 0.0, 1.0)));
    }

    public function testValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SoundCone(1.0, 0.5);
    }

    public function testOuterGainIsBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SoundCone(1.0, 2.0, 1.5);
    }

    public function testAttenuationMapsToTheNativeModels(): void
    {
        $this->assertSame(
            ['model' => 'linear', 'min' => 0.0, 'max' => 10.0, 'rolloff' => 1.0],
            (new Attenuation(0.0, 10.0, AttenuationCurve::Linear))->toNative(),
        );
        $this->assertSame(
            ['model' => 'linear', 'min' => 1.0, 'max' => 50.0, 'rolloff' => 0.0],
            (new Attenuation(curve: AttenuationCurve::None))->toNative(),
        );
        $this->assertSame(
            ['model' => 'inverse', 'min' => 0.001, 'max' => 30.0, 'rolloff' => 2.0],
            (new Attenuation(0.0, 30.0, AttenuationCurve::Inverse, 2.0))->toNative(),
        );
        $this->assertSame('exponential', (new Attenuation(curve: AttenuationCurve::Exponential))->toNative()['model']);
    }

    public function testNativeGainIsTheUntaperedCurveCutAtTheMaximum(): void
    {
        $inverse = new Attenuation(1.0, 50.0, AttenuationCurve::Inverse);
        $this->assertSame(1.0, $inverse->nativeGain(0.5));
        $this->assertEqualsWithDelta(0.25, $inverse->nativeGain(4.0), 1e-12);
        $this->assertGreaterThan($inverse->gain(4.0), $inverse->nativeGain(4.0));
        $this->assertSame(0.0, $inverse->nativeGain(50.0));

        $exp = new Attenuation(2.0, 40.0, AttenuationCurve::Exponential, 2.0);
        $this->assertEqualsWithDelta(0.25, $exp->nativeGain(4.0), 1e-12);

        $linear = new Attenuation(0.0, 10.0, AttenuationCurve::Linear);
        foreach ([0.0, 2.5, 7.0, 10.0, 12.0] as $d) {
            $this->assertSame($linear->gain($d), $linear->nativeGain($d));
        }
        $this->assertSame(1.0, (new Attenuation(curve: AttenuationCurve::None))->nativeGain(1e6));
    }
}
