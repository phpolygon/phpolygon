<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Event\GraphicsSettingsChanged;
use PHPolygon\Event\QualityChangeRequest;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AdaptiveQualityController;
use PHPolygon\Rendering\Quality\QualityMode;

final class AdaptiveQualityControllerTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir() . '/phpolygon_adaptive_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        parent::tearDown();
    }

    private function buildEngine(): Engine
    {
        return new Engine(new EngineConfig(
            headless: true,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->tmpPath,
        ));
    }

    public function testDoesNothingInManualMode(): void
    {
        $engine = $this->buildEngine();
        $this->assertSame(QualityMode::Manual, $engine->graphics->mode());
        $controller = $engine->adaptiveQuality;
        $this->assertNotNull($controller);

        $original = $engine->graphics->settings();
        // Bombard with terrible frame times - manual mode should not react
        for ($i = 0; $i < 200; $i++) {
            $controller->tick(50.0); // 50ms = 20 fps, deeply below the 60 target
        }
        $this->assertEquals($original->toJson(), $engine->graphics->settings()->toJson());
    }

    public function testDowngradesAfterWarmupAndDeadBandViolation(): void
    {
        $engine = $this->buildEngine();
        $engine->graphics->setMode(QualityMode::Adaptive);

        $controller = $engine->adaptiveQuality;
        $this->assertNotNull($controller);

        $previous = $engine->graphics->settings();

        // Warm-up frames (60) are ignored.
        for ($i = 0; $i < 70; $i++) {
            $controller->tick(50.0);
        }

        // Reset the internal cool-down by spoofing the timestamp.
        // We achieve it by re-calling tick repeatedly until the controller
        // commits an adjustment (the cool-down is 1.0 s real time, so we
        // sleep through it once).
        usleep(1_100_000);
        $controller->tick(50.0);

        $current = $engine->graphics->settings();
        $this->assertNotEquals($previous->toJson(), $current->toJson());
    }

    public function testVetoBlocksAdjustmentAndRetriesLater(): void
    {
        $engine = $this->buildEngine();
        $engine->graphics->setMode(QualityMode::Adaptive);

        $controller = $engine->adaptiveQuality;
        $this->assertNotNull($controller);

        $previous = $engine->graphics->settings();
        $vetoCount = 0;
        $engine->events->listen(QualityChangeRequest::class, static function (QualityChangeRequest $e) use (&$vetoCount): void {
            $e->veto();
            $vetoCount++;
        });

        for ($i = 0; $i < 70; $i++) {
            $controller->tick(50.0);
        }
        usleep(1_100_000);
        $controller->tick(50.0);

        // Veto fires, settings unchanged
        $this->assertSame(1, $vetoCount);
        $this->assertEquals($previous->toJson(), $engine->graphics->settings()->toJson());
    }

    public function testWarmupResetsAfterSettingsChange(): void
    {
        $engine = $this->buildEngine();
        $engine->graphics->setMode(QualityMode::Adaptive);
        $controller = $engine->adaptiveQuality;
        $this->assertNotNull($controller);

        $controller->resetWarmup();
        $this->assertSame([], $controller->getSamples());

        $controller->tick(16.0);
        $this->assertCount(1, $controller->getSamples());
    }
}
