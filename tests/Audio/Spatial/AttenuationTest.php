<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Spatial;

use InvalidArgumentException;
use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\AttenuationCurve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttenuationTest extends TestCase
{
    /** @return iterable<string, array{AttenuationCurve}> */
    public static function rollingCurves(): iterable
    {
        yield 'linear' => [AttenuationCurve::Linear];
        yield 'inverse' => [AttenuationCurve::Inverse];
        yield 'exponential' => [AttenuationCurve::Exponential];
    }

    #[DataProvider('rollingCurves')]
    public function testFullInsideMinSilentFromMaxAndMonotonicBetween(AttenuationCurve $curve): void
    {
        $a = new Attenuation(2.0, 40.0, $curve, 1.5);
        $this->assertSame(1.0, $a->gain(0.0));
        $this->assertSame(1.0, $a->gain(2.0));
        $this->assertSame(0.0, $a->gain(40.0));
        $this->assertSame(0.0, $a->gain(1000.0));

        $prev = 1.0;
        for ($d = 2.0; $d <= 40.0; $d += 0.5) {
            $g = $a->gain($d);
            $this->assertLessThanOrEqual($prev + 1e-12, $g, "gain rises at {$d}");
            $this->assertGreaterThanOrEqual(0.0, $g);
            $prev = $g;
        }
        // Continuous at both edges.
        $this->assertEqualsWithDelta(1.0, $a->gain(2.0001), 1e-3);
        $this->assertEqualsWithDelta(0.0, $a->gain(39.999), 1e-3);
    }

    public function testLinearIsAStraightLine(): void
    {
        $a = new Attenuation(0.0, 10.0, AttenuationCurve::Linear);
        $this->assertEqualsWithDelta(0.75, $a->gain(2.5), 1e-12);
        $this->assertEqualsWithDelta(0.5, $a->gain(5.0), 1e-12);
    }

    public function testInverseFallsFasterThanLinearNearTheSource(): void
    {
        $inv = new Attenuation(1.0, 100.0, AttenuationCurve::Inverse);
        $lin = new Attenuation(1.0, 100.0, AttenuationCurve::Linear);
        $this->assertLessThan($lin->gain(5.0), $inv->gain(5.0));
        // Tapered inverse: raw 1/d at d=2 is 0.5, the taper pulls it slightly lower.
        $this->assertEqualsWithDelta((0.5 - 0.01) / 0.99, $inv->gain(2.0), 1e-9);
    }

    public function testRolloffSteepensInverse(): void
    {
        $soft = new Attenuation(1.0, 50.0, AttenuationCurve::Inverse, 0.5);
        $hard = new Attenuation(1.0, 50.0, AttenuationCurve::Inverse, 3.0);
        $this->assertGreaterThan($hard->gain(5.0), $soft->gain(5.0));
    }

    public function testZeroRolloffFallsBackToLinear(): void
    {
        $a = new Attenuation(0.0, 10.0, AttenuationCurve::Exponential, 0.0);
        $this->assertEqualsWithDelta(0.5, $a->gain(5.0), 1e-12);
    }

    public function testNoneNeverAttenuates(): void
    {
        $a = new Attenuation(1.0, 2.0, AttenuationCurve::None);
        $this->assertSame(1.0, $a->gain(500.0));
    }

    public function testRejectsInvalidRadius(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Attenuation(5.0, 5.0);
    }

    public function testWithRadiusKeepsTheCurve(): void
    {
        $a = (new Attenuation(1.0, 10.0, AttenuationCurve::Exponential, 2.0))->withRadius(3.0, 30.0);
        $this->assertSame(AttenuationCurve::Exponential, $a->curve);
        $this->assertSame(2.0, $a->rolloff);
        $this->assertSame(3.0, $a->minDistance);
        $this->assertSame(30.0, $a->maxDistance);
    }
}
