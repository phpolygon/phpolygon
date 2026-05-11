<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Event\GraphicsCalibrationCompleted;
use PHPolygon\Event\GraphicsCalibrationStarted;
use PHPolygon\Rendering\Quality\GraphicsAutoTuner;

final class GraphicsAutoTunerTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir() . '/phpolygon_tuner_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        parent::tearDown();
    }

    public function testHeadlessCalibrationProducesAValidResult(): void
    {
        $engine = new Engine(new EngineConfig(
            headless: true,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->tmpPath,
        ));

        $startEvents = 0;
        $completedEvents = 0;
        $engine->events->listen(GraphicsCalibrationStarted::class, static function () use (&$startEvents): void {
            $startEvents++;
        });
        $engine->events->listen(GraphicsCalibrationCompleted::class, static function () use (&$completedEvents): void {
            $completedEvents++;
        });

        $tuner = new GraphicsAutoTuner($engine, $engine->graphics);
        $result = $tuner->calibrate(60.0);

        $this->assertSame(1, $startEvents);
        $this->assertSame(1, $completedEvents);
        $this->assertNotEmpty($result->tierHistory);
        $this->assertSame(60.0, $result->targetFps);
        $this->assertSame($engine->graphics->hardwareFingerprint(), $result->hardwareFingerprint);
    }

    public function testTunerStepsDownWhenTargetFpsIsAggressive(): void
    {
        $engine = new Engine(new EngineConfig(
            headless: true,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->tmpPath,
        ));

        // 240 fps target on the synthetic estimator forces multiple downgrades.
        $tuner = new GraphicsAutoTuner($engine, $engine->graphics);
        $result = $tuner->calibrate(240.0);

        $this->assertGreaterThan(
            1,
            count($result->tierHistory),
            'Expected the tuner to evaluate more than one tier for a 240 FPS target',
        );

        // The final settings should reflect *something* changing from defaults.
        $defaultJson = json_encode((new \PHPolygon\Rendering\GraphicsSettings())->toJson());
        $finalJson = json_encode($result->finalSettings->toJson());
        $this->assertNotSame($defaultJson, $finalJson);
    }
}
