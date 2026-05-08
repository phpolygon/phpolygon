<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Event\GraphicsSettingsChanged;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\GraphicsSettingsManager;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\Quality\QualityMode;
use PHPolygon\Rendering\Quality\ShadowQuality;

final class GraphicsSettingsManagerTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir() . '/phpolygon_graphics_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        parent::tearDown();
    }

    public function testStartsWithDefaultsWhenNoFileExists(): void
    {
        $m = new GraphicsSettingsManager(path: $this->tmpPath);
        $this->assertEquals((new GraphicsSettings())->toJson(), $m->settings()->toJson());
    }

    public function testSaveWritesJsonFileWithFingerprint(): void
    {
        $m = new GraphicsSettingsManager(path: $this->tmpPath);
        $m->save();
        $this->assertFileExists($this->tmpPath);
        $decoded = json_decode((string)file_get_contents($this->tmpPath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('hardwareFingerprint', $decoded);
        $this->assertArrayHasKey('settings', $decoded);
        $this->assertSame($m->hardwareFingerprint(), $decoded['hardwareFingerprint']);
    }

    public function testLoadRestoresPersistedSettings(): void
    {
        $custom = (new GraphicsSettings())->with(
            mode: QualityMode::Adaptive,
            shadowQuality: ShadowQuality::Low,
            renderScale: 0.7,
        );
        file_put_contents($this->tmpPath, json_encode([
            'version' => 1,
            'hardwareFingerprint' => 'test-fingerprint',
            'settings' => $custom->toJson(),
        ]));

        $m = new GraphicsSettingsManager(path: $this->tmpPath);
        $this->assertSame(QualityMode::Adaptive, $m->settings()->mode);
        $this->assertSame(ShadowQuality::Low, $m->settings()->shadowQuality);
        $this->assertSame(0.7, $m->settings()->renderScale);
    }

    public function testRecommendsRecalibrationWhenFingerprintChanges(): void
    {
        // Save with a deliberately wrong fingerprint
        file_put_contents($this->tmpPath, json_encode([
            'version' => 1,
            'hardwareFingerprint' => 'definitely-not-the-real-fingerprint',
            'settings' => (new GraphicsSettings())->toJson(),
        ]));

        $m = new GraphicsSettingsManager(path: $this->tmpPath);
        $this->assertTrue($m->isRecalibrationRecommended());
    }

    public function testRecalibrationFlagIsClearedAfterAcknowledgement(): void
    {
        file_put_contents($this->tmpPath, json_encode([
            'version' => 1,
            'hardwareFingerprint' => 'mismatched',
            'settings' => (new GraphicsSettings())->toJson(),
        ]));

        $m = new GraphicsSettingsManager(path: $this->tmpPath);
        $this->assertTrue($m->isRecalibrationRecommended());
        $m->clearRecalibrationRecommendation();
        $this->assertFalse($m->isRecalibrationRecommended());
    }

    public function testApplyToRendererForwardsSettings(): void
    {
        // Engine with Null renderer for an isolated test
        $engine = new Engine(new EngineConfig(
            headless: true,
            is3D: true,
            renderBackend3D: 'null',
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->tmpPath,
        ));

        $this->assertInstanceOf(NullRenderer3D::class, $engine->renderer3D);

        $next = $engine->graphics->settings()->with(shadowQuality: ShadowQuality::Low);
        $engine->graphics->update(static fn(GraphicsSettings $s): GraphicsSettings => $next);

        /** @var NullRenderer3D $r */
        $r = $engine->renderer3D;
        $this->assertNotNull($r->getLastAppliedSettings());
        $this->assertSame(ShadowQuality::Low, $r->getLastAppliedSettings()->shadowQuality);
    }

    public function testUpdateEmitsGraphicsSettingsChangedEvent(): void
    {
        $engine = new Engine(new EngineConfig(
            headless: true,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->tmpPath,
        ));

        /** @var list<GraphicsSettingsChanged> $events */
        $events = [];
        $engine->events->listen(GraphicsSettingsChanged::class, static function (GraphicsSettingsChanged $e) use (&$events): void {
            $events[] = $e;
        });

        $engine->graphics->setMode(QualityMode::Adaptive);
        $this->assertCount(1, $events);
        $this->assertSame(QualityMode::Manual, $events[0]->previous->mode);
        $this->assertSame(QualityMode::Adaptive, $events[0]->current->mode);
    }

    public function testNoChangeMeansNoEvent(): void
    {
        $engine = new Engine(new EngineConfig(
            headless: true,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->tmpPath,
        ));

        $events = [];
        $engine->events->listen(GraphicsSettingsChanged::class, static function ($e) use (&$events): void {
            $events[] = $e;
        });

        $current = $engine->graphics->settings();
        $engine->graphics->update(static fn(GraphicsSettings $s) => $current);
        $this->assertCount(0, $events);
    }
}
