<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\Component\WeatherState;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\World;
use PHPolygon\System\WeatherSystem;

class WeatherSystemTest extends TestCase
{
    private function spawn(World $world, object $component): int
    {
        $entity = $world->createEntity();
        $world->attachComponent($entity->id, $component);
        return $entity->id;
    }

    public function testAdvancesStateTimer(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $id = $this->spawn($world, new Weather());

        $world->update(0.5);
        $this->assertEqualsWithDelta(0.5, $world->getComponent($id, Weather::class)->stateTimer, 1e-9);

        $world->update(0.5);
        $this->assertEqualsWithDelta(1.0, $world->getComponent($id, Weather::class)->stateTimer, 1e-9);
    }

    public function testTemperatureFollowsSeasonAndTimeOfDay(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $id = $this->spawn($world, new Weather(cloudCoverage: 0.0));

        $season = new Season();
        $season->baseTemperature = 20.0;
        $this->spawn($world, $season);

        // Noon (timeOfDay = 0.5) → +8°C day modifier, no clouds → full effect
        $this->spawn($world, new DayNightCycle(timeOfDay: 0.5));

        $world->update(0.016);

        $weather = $world->getComponent($id, Weather::class);
        // base 20 + sin((0.5-0.25)*2pi)*8 * (1 - 0*0.4) = 20 + 8 = 28
        $this->assertEqualsWithDelta(28.0, $weather->temperature, 0.05, 'Noon should be warmest');
    }

    public function testDefaultTemperatureWithoutSeasonOrDayNight(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $id = $this->spawn($world, new Weather(cloudCoverage: 0.0));

        // No Season, no DayNightCycle → baseTemp 22, timeOfDay 0.5 → +8
        $world->update(0.016);

        $weather = $world->getComponent($id, Weather::class);
        $this->assertEqualsWithDelta(30.0, $weather->temperature, 0.05, 'Default 22 + 8 at default noon');
    }

    public function testHighHumidityFormsCloudsOverTime(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $id = $this->spawn($world, new Weather(cloudCoverage: 0.0, humidity: 0.5));

        // Season with very high humidity drives cloud target up
        $season = new Season();
        $season->baseHumidity = 1.0;
        $this->spawn($world, $season);

        $startClouds = $world->getComponent($id, Weather::class)->cloudCoverage;
        for ($i = 0; $i < 200; $i++) {
            $world->update(0.5);
        }
        $endClouds = $world->getComponent($id, Weather::class)->cloudCoverage;

        $this->assertGreaterThan($startClouds, $endClouds, 'High humidity should grow cloud coverage');
        $this->assertGreaterThan(0.5, $endClouds, 'Clouds should build up substantially');
    }

    public function testRainAccumulatesUnderWetCloudyWarmConditions(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        // Pre-seed already wet + overcast so precipitation is possible immediately
        $weather = new Weather(cloudCoverage: 0.95, humidity: 0.5, temperature: 20.0);
        $id = $this->spawn($world, $weather);

        $season = new Season();
        $season->baseHumidity = 1.0;       // pushes humidity high
        $season->baseTemperature = 25.0;   // warm → rain not snow
        $this->spawn($world, $season);
        $this->spawn($world, new DayNightCycle(timeOfDay: 0.5));

        for ($i = 0; $i < 300; $i++) {
            $world->update(0.5);
        }

        $weather = $world->getComponent($id, Weather::class);
        $this->assertGreaterThan(0.0, $weather->rainIntensity, 'Warm wet overcast weather should rain');
        $this->assertGreaterThan(2.0, $weather->temperature, 'Should be warm enough for rain not snow');
        $this->assertSame(0.0, $weather->snowIntensity, 'No snow when warm');
    }

    public function testPrecipitationFadesWhenConditionsClear(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $weather = new Weather(cloudCoverage: 0.0, humidity: 0.0, temperature: 22.0);
        // Pre-existing rain that should fade away since clouds/humidity are low
        $weather->rainIntensity = 0.8;
        $id = $this->spawn($world, $weather);

        $season = new Season();
        $season->baseHumidity = 0.0;
        $this->spawn($world, $season);

        for ($i = 0; $i < 100; $i++) {
            $world->update(0.5);
        }

        $this->assertSame(0.0, $world->getComponent($id, Weather::class)->rainIntensity, 'Rain fades to zero when dry');
    }

    public function testTinyIntensitiesAreKilledToZero(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $weather = new Weather(cloudCoverage: 0.0, humidity: 0.0);
        $weather->snowIntensity = 0.005; // below the 0.01 kill threshold
        $weather->sandstormIntensity = 0.005;
        $weather->stormIntensity = 0.005;
        $weather->fogDensity = 0.005;
        $id = $this->spawn($world, $weather);

        $world->update(0.016);

        $w = $world->getComponent($id, Weather::class);
        $this->assertSame(0.0, $w->snowIntensity);
        $this->assertSame(0.0, $w->sandstormIntensity);
        $this->assertSame(0.0, $w->stormIntensity);
        $this->assertSame(0.0, $w->fogDensity);
    }

    public function testStateLabelReflectsClearCalm(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $id = $this->spawn($world, new Weather(cloudCoverage: 0.0, humidity: 0.1));

        $season = new Season();
        $season->baseHumidity = 0.1; // stays dry/clear
        $this->spawn($world, $season);

        $world->update(0.016);

        $this->assertSame(WeatherState::Clear, $world->getComponent($id, Weather::class)->state);
    }

    public function testStateLabelBecomesStormWhenStormy(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $weather = new Weather(cloudCoverage: 0.9, humidity: 0.9, temperature: 25.0);
        $weather->stormIntensity = 0.9; // > 0.3 storm threshold
        $id = $this->spawn($world, $weather);

        $world->update(0.016);

        $this->assertSame(WeatherState::Storm, $world->getComponent($id, Weather::class)->state);
    }

    public function testSandstormFormsWithStrongDryWind(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());
        $weather = new Weather(cloudCoverage: 0.0, humidity: 0.1);
        $id = $this->spawn($world, $weather);

        // Strong wind (> 0.8) + dry (humidity < 0.3) → sandstorm
        $wind = new Wind();
        $wind->intensity = 1.5;
        $this->spawn($world, $wind);

        $season = new Season();
        $season->baseHumidity = 0.1;
        $this->spawn($world, $season);

        for ($i = 0; $i < 60; $i++) {
            $world->update(0.5);
        }

        $this->assertGreaterThan(0.0, $world->getComponent($id, Weather::class)->sandstormIntensity, 'Strong dry wind raises a sandstorm');
    }

    public function testEmptyWorldIsNoOp(): void
    {
        $world = new World();
        $world->addSystem(new WeatherSystem());

        $world->update(0.016); // no Weather entity → returns early, no throw

        $this->assertSame(0, $world->componentCount(Weather::class));
    }
}
