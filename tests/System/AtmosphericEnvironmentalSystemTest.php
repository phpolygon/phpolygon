<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Atmosphere;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\World;
use PHPolygon\System\AtmosphericEnvironmentalSystem;

class AtmosphericEnvironmentalSystemTest extends TestCase
{
    private function spawn(World $world, object $component): int
    {
        $e = $world->createEntity();
        $world->attachComponent($e->id, $component);
        return $e->id;
    }

    /** Attach Atmosphere + Weather to the same entity (as documented). */
    private function spawnEnvironment(World $world, Atmosphere $atmo, Weather $weather): int
    {
        $e = $world->createEntity();
        $world->attachComponent($e->id, $atmo);
        $world->attachComponent($e->id, $weather);
        return $e->id;
    }

    public function testAdvancesSimulationTime(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());
        $id = $this->spawnEnvironment($world, new Atmosphere(), new Weather());

        $world->update(2.0);
        $this->assertEqualsWithDelta(2.0, $world->getComponent($id, Atmosphere::class)->simulationTime, 1e-9);
    }

    public function testStillRunsParentEnvironmentalCoupling(): void
    {
        // It extends EnvironmentalSystem, so Season + Weather should still evolve.
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        $atmoWeatherId = $this->spawnEnvironment($world, new Atmosphere(), new Weather());
        $seasonId = $this->spawn($world, new Season(yearProgress: 0.0, yearDuration: 100.0));

        $world->update(1.0);

        $this->assertGreaterThan(0.0, $world->getComponent($seasonId, Season::class)->yearProgress, 'Parent Season coupling ran');
        $this->assertEqualsWithDelta(1.0, $world->getComponent($atmoWeatherId, Weather::class)->stateTimer, 1e-9, 'Parent Weather coupling ran');
    }

    public function testPressureDriftsTowardSeasonalTarget(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        // Start far below ISA; with a long sim and time the pressure should
        // relax toward its computed target (somewhere in the 945–1050 band).
        $atmo = new Atmosphere(airPressure: 970.0);
        $id = $this->spawnEnvironment($world, $atmo, new Weather());

        $start = $world->getComponent($id, Atmosphere::class)->airPressure;
        for ($i = 0; $i < 500; $i++) {
            $world->update(1.0);
        }
        $end = $world->getComponent($id, Atmosphere::class)->airPressure;

        // Pressure must move (the system writes it) and stay in the physical band.
        $this->assertNotEqualsWithDelta($start, $end, 1e-3, 'Pressure should drift over time');
        $this->assertGreaterThanOrEqual(945.0, $end, 'Pressure clamped low');
        $this->assertLessThanOrEqual(1050.0, $end, 'Pressure clamped high');
    }

    public function testPressureTrendComputed(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());
        $atmo = new Atmosphere(airPressure: 990.0);
        $id = $this->spawnEnvironment($world, $atmo, new Weather());

        $world->update(1.0);

        // pressureTrend = (airPressure - pressurePrev) / dt — must be a finite number
        $trend = $world->getComponent($id, Atmosphere::class)->pressureTrend;
        $this->assertIsFloat($trend);
        $this->assertTrue(is_finite($trend), 'Pressure trend must be finite');
    }

    public function testDewPointDerivedFromTemperatureAndHumidity(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        $weather = new Weather(cloudCoverage: 0.0, humidity: 1.0, temperature: 20.0);
        $atmo = new Atmosphere();
        $id = $this->spawnEnvironment($world, $atmo, $weather);

        // No Season/DayNight → temperature recomputed to base 22 + day mod, humidity high
        $world->update(0.016);

        $a = $world->getComponent($id, Atmosphere::class);
        $w = $world->getComponent($id, Weather::class);
        // dewPoint = temp - (100 - rh)/5 ; at rh=100 dewPoint == temperature
        $expected = $w->temperature - ((100.0 - $w->humidity * 100.0) / 5.0);
        $this->assertEqualsWithDelta($expected, $a->dewPoint, 1e-6, 'Dew point follows Magnus approximation');
    }

    public function testVisibilityReducedByFogAndPrecipitation(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        $weather = new Weather();
        $weather->fogDensity = 0.9;
        $weather->rainIntensity = 0.5;
        $atmo = new Atmosphere();
        $id = $this->spawnEnvironment($world, $atmo, $weather);

        $world->update(0.016);

        $vis = $world->getComponent($id, Atmosphere::class)->visibility;
        $this->assertLessThan(30000.0, $vis, 'Fog + rain reduce visibility below clear-day max');
        $this->assertGreaterThanOrEqual(50.0, $vis, 'Visibility never drops below the floor');
    }

    public function testClearWeatherKeepsHighVisibility(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        // Dry, clear weather → minimal haze, high visibility
        $weather = new Weather(cloudCoverage: 0.0, humidity: 0.2);
        $atmo = new Atmosphere();
        $id = $this->spawnEnvironment($world, $atmo, $weather);

        $world->update(0.016);

        $vis = $world->getComponent($id, Atmosphere::class)->visibility;
        $this->assertGreaterThan(20000.0, $vis, 'Clear dry weather keeps visibility high');
    }

    public function testThermalsRiseWithSunAndWarmMoistAir(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        $weather = new Weather(cloudCoverage: 0.3, humidity: 0.7, temperature: 30.0);
        $atmo = new Atmosphere();
        $id = $this->spawnEnvironment($world, $atmo, $weather);

        // High sun at noon, warm season
        $season = new Season();
        $season->baseTemperature = 30.0;
        $season->baseHumidity = 0.7;
        $this->spawn($world, $season);
        $this->spawn($world, new DayNightCycle(timeOfDay: 0.5));

        $start = $world->getComponent($id, Atmosphere::class)->thermalIntensity;
        for ($i = 0; $i < 50; $i++) {
            $world->update(1.0);
        }
        $end = $world->getComponent($id, Atmosphere::class)->thermalIntensity;

        $this->assertGreaterThan($start, $end, 'Warm moist sunny conditions build thermals');
        $this->assertLessThanOrEqual(1.0, $end, 'Thermal intensity clamped to 1');
    }

    public function testThermalsDecayWithoutDayNight(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        $weather = new Weather();
        $atmo = new Atmosphere();
        $atmo->thermalIntensity = 0.8; // pre-seed
        $id = $this->spawnEnvironment($world, $atmo, $weather);

        // No DayNightCycle entity → thermals decay
        for ($i = 0; $i < 20; $i++) {
            $world->update(0.5);
        }

        $this->assertLessThan(0.8, $world->getComponent($id, Atmosphere::class)->thermalIntensity, 'Thermals decay without sun');
    }

    public function testCloudTypeFractionsUpdated(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        $weather = new Weather(cloudCoverage: 0.9, humidity: 0.9, temperature: 25.0);
        $weather->stormIntensity = 0.8; // drives cumulonimbus up
        $atmo = new Atmosphere();
        $atmo->cumulonimbusFraction = 0.0;
        $id = $this->spawnEnvironment($world, $atmo, $weather);

        for ($i = 0; $i < 30; $i++) {
            $world->update(1.0);
        }

        $a = $world->getComponent($id, Atmosphere::class);
        $this->assertGreaterThan(0.0, $a->cumulonimbusFraction, 'Storm grows cumulonimbus fraction');
        $this->assertGreaterThan(0.0, $a->cloudBaseAltitude, 'Cloud base altitude stays positive');
    }

    public function testMissingAtmosphereIsNoOpAfterParent(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        // Only a Weather entity, no Atmosphere → atmospheric layer skipped, no throw
        $weatherId = $this->spawn($world, new Weather());

        $world->update(1.0);

        // Parent EnvironmentalSystem still ran the Weather evolution
        $this->assertEqualsWithDelta(1.0, $world->getComponent($weatherId, Weather::class)->stateTimer, 1e-9);
    }

    public function testEmptyWorldIsNoOp(): void
    {
        $world = new World();
        $world->addSystem(new AtmosphericEnvironmentalSystem());

        $world->update(1.0); // nothing attached → no throw

        $this->assertSame(0, $world->entityCount());
    }
}
