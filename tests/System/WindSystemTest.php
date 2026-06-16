<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Weather;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\World;
use PHPolygon\System\WindSystem;

class WindSystemTest extends TestCase
{
    private function spawnWind(World $world, Wind $wind): int
    {
        $entity = $world->createEntity();
        $world->attachComponent($entity->id, $wind);
        return $entity->id;
    }

    public function testAdvancesWindTime(): void
    {
        $world = new World();
        $world->addSystem(new WindSystem());

        $id = $this->spawnWind($world, new Wind());

        $world->update(0.5);

        $wind = $world->getComponent($id, Wind::class);
        $this->assertEqualsWithDelta(0.5, $wind->time, 1e-9, 'Wind time accumulates dt');

        $world->update(0.25);
        $this->assertEqualsWithDelta(0.75, $wind->time, 1e-9, 'Wind time keeps accumulating');
    }

    public function testIntensityGustsVaryOverTime(): void
    {
        $world = new World();
        $world->addSystem(new WindSystem());

        // gustiness high so the layered sine gusts produce visible variation
        $id = $this->spawnWind($world, new Wind(baseIntensity: 0.5, gustiness: 1.0));

        $samples = [];
        for ($i = 0; $i < 30; $i++) {
            $world->update(0.2);
            $samples[] = $world->getComponent($id, Wind::class)->intensity;
        }

        // Intensity must change across frames (not a frozen constant)
        $distinct = array_unique(array_map(fn(float $v) => round($v, 4), $samples));
        $this->assertGreaterThan(1, count($distinct), 'Gusts should make intensity vary over time');

        // All samples must be clamped to >= 0
        foreach ($samples as $v) {
            $this->assertGreaterThanOrEqual(0.0, $v, 'Intensity is clamped to non-negative');
        }
    }

    public function testIntensityNeverNegativeWithStrongGusts(): void
    {
        $world = new World();
        $world->addSystem(new WindSystem());

        // Tiny base, huge gustiness — raw gust term could go very negative
        $id = $this->spawnWind($world, new Wind(baseIntensity: 0.05, gustiness: 5.0));

        for ($i = 0; $i < 100; $i++) {
            $world->update(0.1);
            $this->assertGreaterThanOrEqual(
                0.0,
                $world->getComponent($id, Wind::class)->intensity,
                'Intensity must stay clamped at 0 even with large negative gusts',
            );
        }
    }

    public function testStormAmplifiesWind(): void
    {
        // Calm scene (no storm)
        $calmWorld = new World();
        $calmWorld->addSystem(new WindSystem());
        $calmId = $this->spawnWind($calmWorld, new Wind(baseIntensity: 0.5, gustiness: 0.0));

        // Identical scene but with a strong storm in the Weather entity
        $stormWorld = new World();
        $stormWorld->addSystem(new WindSystem());
        $stormId = $this->spawnWind($stormWorld, new Wind(baseIntensity: 0.5, gustiness: 0.0));
        $weather = new Weather();
        $weather->stormIntensity = 1.0;
        $we = $stormWorld->createEntity();
        $stormWorld->attachComponent($we->id, $weather);

        // gustiness 0 isolates the baseIntensity * stormBoost term so the
        // comparison is deterministic regardless of the sine gust phase.
        $calmWorld->update(0.016);
        $stormWorld->update(0.016);

        $calm = $calmWorld->getComponent($calmId, Wind::class)->intensity;
        $storm = $stormWorld->getComponent($stormId, Wind::class)->intensity;

        $this->assertGreaterThan($calm, $storm, 'Storm should amplify wind intensity');
        // stormBoost = 1 + 1.0 * 1.5 = 2.5 → 0.5 * 2.5 = 1.25
        $this->assertEqualsWithDelta(1.25, $storm, 1e-6, 'Storm boost is base * (1 + storm*1.5)');
    }

    public function testEmptyWorldIsNoOp(): void
    {
        $world = new World();
        $world->addSystem(new WindSystem());

        // No Wind entity at all — must not throw
        $world->update(0.016);

        $this->assertSame(0, $world->componentCount(Wind::class));
    }
}
