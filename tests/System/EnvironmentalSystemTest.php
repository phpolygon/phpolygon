<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\World;
use PHPolygon\System\EnvironmentalSystem;

class EnvironmentalSystemTest extends TestCase
{
    private function spawn(World $world, object $component): int
    {
        $e = $world->createEntity();
        $world->attachComponent($e->id, $component);
        return $e->id;
    }

    public function testAdvancesSeasonAndWeather(): void
    {
        $world = new World();
        $world->addSystem(new EnvironmentalSystem());

        $seasonId = $this->spawn($world, new Season(yearProgress: 0.0, yearDuration: 100.0, speed: 1.0));
        $weatherId = $this->spawn($world, new Weather());

        $world->update(1.0);

        // SeasonSystem advanced yearProgress
        $this->assertGreaterThan(0.0, $world->getComponent($seasonId, Season::class)->yearProgress, 'Season advances');
        // WeatherSystem advanced stateTimer
        $this->assertEqualsWithDelta(1.0, $world->getComponent($weatherId, Weather::class)->stateTimer, 1e-9, 'Weather evolves');
    }

    public function testCouplesStormToWindBounds(): void
    {
        $world = new World();
        $world->addSystem(new EnvironmentalSystem());

        $weather = new Weather(cloudCoverage: 0.9, humidity: 0.9, temperature: 25.0);
        $weather->stormIntensity = 1.0;
        $weather->rainIntensity = 1.0;
        $weatherId = $this->spawn($world, $weather);

        $windId = $this->spawn($world, new Wind());

        $world->update(0.016);

        // WeatherSystem (run inside EnvironmentalSystem) evolves the intensities
        // slightly before the coupling reads them, so assert against the live values.
        $w = $world->getComponent($weatherId, Weather::class);
        $wind = $world->getComponent($windId, Wind::class);
        $this->assertEqualsWithDelta(1.0 + $w->stormIntensity * 0.5, $wind->maxIntensity, 1e-6, 'Storm raises max wind bound');
        $this->assertEqualsWithDelta(
            0.15 + $w->rainIntensity * 0.15 + $w->stormIntensity * 0.2,
            $wind->minIntensity,
            1e-6,
            'Rain + storm raise min wind bound',
        );
        // Sanity: a strong storm still produces clearly elevated bounds.
        $this->assertGreaterThan(1.4, $wind->maxIntensity, 'Strong storm clearly raises max bound');
        $this->assertGreaterThan(0.4, $wind->minIntensity, 'Strong storm clearly raises min bound');
    }

    public function testCalmWeatherLeavesLowWindBounds(): void
    {
        $world = new World();
        $world->addSystem(new EnvironmentalSystem());

        // Clear weather, no storm/rain
        $this->spawn($world, new Weather(cloudCoverage: 0.0, humidity: 0.0));
        $windId = $this->spawn($world, new Wind());

        $world->update(0.016);

        $wind = $world->getComponent($windId, Wind::class);
        $this->assertEqualsWithDelta(1.0, $wind->maxIntensity, 1e-6, 'Calm weather → baseline max bound');
        $this->assertEqualsWithDelta(0.15, $wind->minIntensity, 1e-6, 'Calm weather → baseline min bound');
    }

    public function testCouplesSeasonAndWeatherToDayNight(): void
    {
        $world = new World();
        $world->addSystem(new EnvironmentalSystem());

        $season = new Season();
        $season->axialTilt = 12.5;
        $seasonId = $this->spawn($world, $season);

        $weather = new Weather(cloudCoverage: 0.8);
        $weather->lightningFlash = 0.7;
        $weatherId = $this->spawn($world, $weather);

        $dnId = $this->spawn($world, new DayNightCycle());

        $world->update(0.016);

        $dn = $world->getComponent($dnId, DayNightCycle::class);
        $w = $world->getComponent($weatherId, Weather::class);
        // axialTilt is mirrored from the (SeasonSystem-recomputed) live Season value
        $liveTilt = $world->getComponent($seasonId, Season::class)->axialTilt;
        $this->assertEqualsWithDelta(
            $liveTilt,
            $dn->axialTilt,
            1e-6,
            'Axial tilt mirrored onto DayNightCycle',
        );
        // cloudDarkening = cloudCoverage * 0.5 ; weather cloudCoverage barely changes in one frame
        $this->assertGreaterThan(0.0, $dn->cloudDarkening, 'Clouds darken the day');
        // WeatherSystem decays lightningFlash before the coupling copies it, so
        // compare against the live (post-update) Weather value, not the seed.
        $this->assertEqualsWithDelta($w->lightningFlash, $dn->lightningFlash, 1e-6, 'Lightning flash mirrored onto DayNightCycle');
        $this->assertGreaterThan(0.0, $dn->lightningFlash, 'A flash was propagated');
    }

    public function testDayNightCloudDarkeningMatchesCoverage(): void
    {
        $world = new World();
        $world->addSystem(new EnvironmentalSystem());

        $weather = new Weather(cloudCoverage: 0.6);
        $weatherId = $this->spawn($world, $weather);
        $dnId = $this->spawn($world, new DayNightCycle());

        $world->update(0.016);

        $coverage = $world->getComponent($weatherId, Weather::class)->cloudCoverage;
        $dn = $world->getComponent($dnId, DayNightCycle::class);
        $this->assertEqualsWithDelta($coverage * 0.5, $dn->cloudDarkening, 1e-6, 'cloudDarkening = coverage * 0.5');
    }

    public function testEmptyWorldIsNoOp(): void
    {
        $world = new World();
        $world->addSystem(new EnvironmentalSystem());

        $world->update(0.016); // no components → returns early everywhere

        $this->assertSame(0, $world->entityCount());
    }
}
