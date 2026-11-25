<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Weather;

final class WeatherLightDarknessTest extends TestCase
{
    public function testClearSkyIsNotDark(): void
    {
        self::assertSame(0.0, (new Weather(cloudCoverage: 0.3))->lightDarkness());
        self::assertSame(0.0, (new Weather(cloudCoverage: 0.5))->lightDarkness());
    }

    public function testOvercastCloudCapsAtThreeQuarters(): void
    {
        self::assertEqualsWithDelta(0.375, (new Weather(cloudCoverage: 0.75))->lightDarkness(), 1e-9);
        self::assertEqualsWithDelta(0.75, (new Weather(cloudCoverage: 1.0))->lightDarkness(), 1e-9);
        self::assertEqualsWithDelta(0.75, (new Weather(cloudCoverage: 3.0))->lightDarkness(), 1e-9);
    }

    public function testTheStrongestTermWins(): void
    {
        $w = new Weather(cloudCoverage: 1.0);
        $w->rainIntensity = 0.9;
        self::assertEqualsWithDelta(0.9, $w->lightDarkness(), 1e-9);

        $w = new Weather(cloudCoverage: 0.0);
        $w->snowIntensity = 0.4;
        $w->stormIntensity = 0.6;
        $w->fogDensity = 0.2;
        self::assertEqualsWithDelta(0.6, $w->lightDarkness(), 1e-9);

        $w = new Weather(cloudCoverage: 0.0);
        $w->fogDensity = 0.8;
        self::assertEqualsWithDelta(0.8, $w->lightDarkness(), 1e-9);
    }

    public function testResultIsClampedToOne(): void
    {
        $w = new Weather();
        $w->stormIntensity = 4.0;
        self::assertSame(1.0, $w->lightDarkness());
        $w->stormIntensity = -2.0;
        self::assertSame(0.0, $w->lightDarkness());
    }
}
