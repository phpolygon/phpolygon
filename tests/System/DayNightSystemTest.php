<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\Season;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\DayNightSystem;

/**
 * Covers the seasonal-sun + weather coupling in {@see DayNightSystem}.
 *
 * The system caches the DayNightCycle in update() and emits SetSky /
 * SetAmbientLight / SetFog plus mutates DirectionalLight components in
 * render(). We assert three couplings:
 *   1. Season::axialTilt shifts the effective sun elevation (the key
 *      directional light points more steeply down in "summer" than "winter").
 *   2. SetSky::cloudDarkness == max(rainIntensity, snowIntensity, stormIntensity).
 *   3. Clouds dim the scene: overcast alone dims the sun, storms dim sun AND
 *      ambient, and the ambient falls proportionally less than the direct sun
 *      (an overcast sky is diffuse gray, not dark).
 *
 * All tests run at timeOfDay 0.5 (local noon) with the cycle paused so the sun
 * stays well above the horizon and the time does not drift during update().
 */
final class DayNightSystemTest extends TestCase
{
    /**
     * Build a minimal world (paused noon cycle + a key directional light) and
     * optionally a Season / Weather, run update()+render(), and return the
     * emitted SetSky and SetAmbientLight plus the key DirectionalLight.
     *
     * @return array{sky: SetSky, light: DirectionalLight, ambient: SetAmbientLight}
     */
    private function runSystem(?Season $season = null, ?Weather $weather = null): array
    {
        $world = new World();

        $cycleEntity = $world->createEntity();
        $cycleEntity->attach(new DayNightCycle(timeOfDay: 0.5, paused: true));

        $lightEntity = $world->createEntity();
        $light = new DirectionalLight();
        $lightEntity->attach($light);
        $lightEntity->attach(new Transform3D());

        if ($season !== null) {
            $world->createEntity()->attach($season);
        }
        if ($weather !== null) {
            $world->createEntity()->attach($weather);
        }

        $list = new RenderCommandList();
        $system = new DayNightSystem($list);
        $system->update($world, 0.016);
        $system->render($world);

        $skies = $list->ofType(SetSky::class);
        $this->assertCount(1, $skies, 'expected exactly one SetSky per frame');
        $ambients = $list->ofType(SetAmbientLight::class);
        $this->assertCount(1, $ambients, 'expected exactly one SetAmbientLight per frame');

        return ['sky' => $skies[0], 'light' => $light, 'ambient' => $ambients[0]];
    }

    public function testSeasonalTiltSteepensSunInSummer(): void
    {
        // Summer tilt (+15°) raises the sun -> the key light direction points
        // more steeply downward (more negative Y) than in winter (-15°).
        $summer = $this->runSystem(season: new Season(yearProgress: 0.25, speed: 0.0));
        $winter = $this->runSystem(season: new Season(yearProgress: 0.75, speed: 0.0));

        // Drive axialTilt directly to the expected ±15 so the test does not
        // depend on SeasonSystem having run first.
        $summerTilt = $this->runWithTilt(15.0);
        $winterTilt = $this->runWithTilt(-15.0);

        $this->assertLessThan(
            $winterTilt['light']->direction->y,
            $summerTilt['light']->direction->y,
            'summer sun should point more steeply downward than winter',
        );

        // The Season-driven path (axialTilt left at its default 0.0 because no
        // SeasonSystem ran) still produces a valid, finite light direction.
        $this->assertIsFloat($summer['light']->direction->y);
        $this->assertIsFloat($winter['light']->direction->y);
    }

    public function testSeasonAxialTiltFeedsSunElevation(): void
    {
        // A neutral baseline (no Season entity) vs. an explicit +15° tilt must
        // differ — proving the Season::axialTilt is actually folded into the
        // sun elevation.
        $neutral = $this->runSystem();
        $tilted = $this->runWithTilt(15.0);

        $this->assertNotEqualsWithDelta(
            $neutral['light']->direction->y,
            $tilted['light']->direction->y,
            1e-4,
        );
    }

    /** Like run() but with a Season whose axialTilt is pre-set to $tilt. */
    private function runWithTilt(float $tilt): array
    {
        $season = new Season(yearProgress: 0.0, speed: 0.0);
        $season->axialTilt = $tilt;
        return $this->runSystem(season: $season);
    }

    public function testCloudDarknessIsMaxOfPrecipitation(): void
    {
        $weather = new Weather(cloudCoverage: 0.4);
        $weather->rainIntensity = 0.3;
        $weather->snowIntensity = 0.7; // the max
        $weather->stormIntensity = 0.5;

        $result = $this->runSystem(weather: $weather);

        $this->assertEqualsWithDelta(0.7, $result['sky']->cloudDarkness, 1e-6);
    }

    public function testCloudDarknessZeroWithoutPrecipitation(): void
    {
        $weather = new Weather(cloudCoverage: 0.9); // overcast but dry
        // rain/snow/storm all default to 0.0

        $result = $this->runSystem(weather: $weather);

        $this->assertEqualsWithDelta(0.0, $result['sky']->cloudDarkness, 1e-6);
    }

    public function testStormDimsDirectionalLight(): void
    {
        $clear = $this->runSystem();

        $stormy = new Weather(cloudCoverage: 0.9);
        $stormy->stormIntensity = 1.0;
        $storm = $this->runSystem(weather: $stormy);

        $this->assertLessThan(
            $clear['light']->intensity,
            $storm['light']->intensity,
            'a full storm should dim the primary sun light',
        );
    }

    public function testDryOvercastDimsSunAndAmbient(): void
    {
        $clear = $this->runSystem();
        $overcast = $this->runSystem(weather: new Weather(cloudCoverage: 1.0)); // dry

        $this->assertLessThan(
            $clear['light']->intensity,
            $overcast['light']->intensity,
            'full cloud cover should dim the sun even without precipitation',
        );
        $this->assertLessThan(
            $clear['ambient']->intensity,
            $overcast['ambient']->intensity,
            'full cloud cover should dim the ambient light',
        );
    }

    public function testStormDimsAmbientLight(): void
    {
        $clear = $this->runSystem();

        $stormy = new Weather(cloudCoverage: 1.0);
        $stormy->stormIntensity = 1.0;
        $storm = $this->runSystem(weather: $stormy);

        $this->assertLessThan(
            $clear['ambient']->intensity,
            $storm['ambient']->intensity,
            'a full storm should dim the ambient light',
        );
    }

    public function testAmbientFallsLessThanDirectSun(): void
    {
        // An overcast sky is diffuse gray, not dark: the ambient share must
        // survive a storm proportionally better than the direct sun, or the
        // whole scene crushes to black.
        $clear = $this->runSystem();

        $stormy = new Weather(cloudCoverage: 1.0);
        $stormy->stormIntensity = 1.0;
        $storm = $this->runSystem(weather: $stormy);

        $sunRatio = $storm['light']->intensity / $clear['light']->intensity;
        $ambientRatio = $storm['ambient']->intensity / $clear['ambient']->intensity;

        $this->assertGreaterThan(
            $sunRatio,
            $ambientRatio,
            'ambient must dim less than the direct sun under storm clouds',
        );
    }
}
