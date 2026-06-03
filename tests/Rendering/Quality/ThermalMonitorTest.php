<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Event\TargetFpsChanged;
use PHPolygon\Event\ThermalStateChanged;
use PHPolygon\Rendering\Quality\PressureSignal;
use PHPolygon\Rendering\Quality\ThermalMonitor;
use PHPolygon\Rendering\Quality\ThermalSourceInterface;
use PHPolygon\Runtime\HardwareProfile;
use PHPolygon\Runtime\ThermalProfile;

/**
 * Mock ThermalSource that can be flipped between PressureSignals during
 * a test scenario so we can observe ThermalMonitor's response.
 */
final class StubThermalSource implements ThermalSourceInterface
{
    public PressureSignal $signal = PressureSignal::Nominal;
    public function __construct(private readonly string $name) {}
    public function name(): string { return $this->name; }
    public function update(float $frameTimeMs, float $nowSeconds, float $currentTargetFps): PressureSignal
    {
        return $this->signal;
    }
}

final class ThermalMonitorTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir() . '/phpolygon_thermal_test_' . uniqid() . '.json';
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
            autoThermalManagement: true,
        ));
    }

    private function buildMonitor(Engine $engine, StubThermalSource $source, ?HardwareProfile $profile = null): ThermalMonitor
    {
        $profile ??= new HardwareProfile(
            vendor: 'Intel',
            cpuBrand: 'Intel(R) Core(TM) i9-9980HK',
            osFamily: 'Darwin',
            thermalProfile: ThermalProfile::IntelI9_2018_15inch,
        );
        return new ThermalMonitor(
            engine: $engine,
            profile: $profile,
            sources: [$source],
        );
    }

    public function testNominalPressureLeavesTargetFpsAlone(): void
    {
        $engine = $this->buildEngine();
        $source = new StubThermalSource('test');
        $monitor = $this->buildMonitor($engine, $source);
        $initial = $engine->graphics->settings()->targetFps;
        $monitor->tick(16.6);
        $this->assertSame($initial, $engine->graphics->settings()->targetFps);
    }

    public function testSeriousPressureLowersTargetFpsToFortyFive(): void
    {
        $engine = $this->buildEngine();
        $source = new StubThermalSource('test');
        $monitor = $this->buildMonitor($engine, $source);

        $source->signal = PressureSignal::Serious;
        $monitor->tick(16.6);
        $this->assertSame(45.0, $engine->graphics->settings()->targetFps);
    }

    public function testCriticalPressureLowersTargetFpsToThirty(): void
    {
        $engine = $this->buildEngine();
        $source = new StubThermalSource('test');
        $monitor = $this->buildMonitor($engine, $source);

        $source->signal = PressureSignal::Critical;
        $monitor->tick(16.6);
        $this->assertSame(30.0, $engine->graphics->settings()->targetFps);
    }

    public function testRecoveryRequiresFullHoldTime(): void
    {
        $engine = $this->buildEngine();
        $source = new StubThermalSource('test');
        $monitor = $this->buildMonitor($engine, $source);

        // Drop to 45.
        $source->signal = PressureSignal::Serious;
        $monitor->tick(16.6);
        $this->assertSame(45.0, $engine->graphics->settings()->targetFps);

        // Back to nominal but in quick succession - should NOT immediately
        // ramp up because RAMP_UP_HOLD_TIME_S has not elapsed.
        $source->signal = PressureSignal::Nominal;
        for ($i = 0; $i < 5; $i++) {
            $monitor->tick(16.6);
        }
        $this->assertSame(45.0, $engine->graphics->settings()->targetFps);
    }

    public function testRampUpRespectsHardwareCeiling(): void
    {
        $engine = $this->buildEngine();
        $source = new StubThermalSource('test');
        $profile = new HardwareProfile(
            vendor: 'Intel',
            cpuBrand: 'Intel(R) Core(TM) i9-9980HK',
            osFamily: 'Darwin',
            thermalProfile: ThermalProfile::IntelI9_2018_15inch, // ceiling 50
        );
        $monitor = $this->buildMonitor($engine, $source, $profile);
        $this->assertSame(50.0, $monitor->ceiling());
    }

    public function testTargetFpsChangedEventFiresOnPressure(): void
    {
        $engine = $this->buildEngine();
        $captured = [];
        $engine->events->listen(TargetFpsChanged::class, function (TargetFpsChanged $e) use (&$captured) {
            $captured[] = $e;
        });

        $source = new StubThermalSource('frametime_guard');
        $monitor = $this->buildMonitor($engine, $source);

        $source->signal = PressureSignal::Serious;
        $monitor->tick(16.6);

        $this->assertCount(1, $captured);
        $this->assertSame(45.0, $captured[0]->current);
        $this->assertSame('frametime_guard', $captured[0]->source);
    }

    public function testThermalStateChangedEventFiresOnLevelChange(): void
    {
        $engine = $this->buildEngine();
        $captured = [];
        $engine->events->listen(ThermalStateChanged::class, function (ThermalStateChanged $e) use (&$captured) {
            $captured[] = $e;
        });

        $source = new StubThermalSource('thermal_macos');
        $monitor = $this->buildMonitor($engine, $source);

        $source->signal = PressureSignal::Critical;
        $monitor->tick(16.6);

        $this->assertCount(1, $captured);
        $this->assertSame(PressureSignal::Nominal, $captured[0]->previous);
        $this->assertSame(PressureSignal::Critical, $captured[0]->current);
        $this->assertSame('thermal_macos', $captured[0]->source);
    }

    public function testDisablingAutoThermalManagementSilences(): void
    {
        $engine = new Engine(new EngineConfig(
            headless: true,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->tmpPath,
            autoThermalManagement: false,
        ));
        $source = new StubThermalSource('test');
        $monitor = $this->buildMonitor($engine, $source);

        $initial = $engine->graphics->settings()->targetFps;
        $source->signal = PressureSignal::Critical;
        for ($i = 0; $i < 10; $i++) {
            $monitor->tick(50.0);
        }
        $this->assertSame($initial, $engine->graphics->settings()->targetFps);
    }

    public function testMaxAggregationPicksWorseSource(): void
    {
        $engine = $this->buildEngine();
        $a = new StubThermalSource('source_a');
        $b = new StubThermalSource('source_b');
        $profile = new HardwareProfile(
            vendor: 'Intel',
            cpuBrand: 'i9-9980HK',
            osFamily: 'Darwin',
            thermalProfile: ThermalProfile::IntelI9_2018_15inch,
        );
        $monitor = new ThermalMonitor(
            engine: $engine,
            profile: $profile,
            sources: [$a, $b],
        );

        $a->signal = PressureSignal::Fair;
        $b->signal = PressureSignal::Critical;
        $monitor->tick(16.6);

        // Critical wins -> 30 fps.
        $this->assertSame(30.0, $engine->graphics->settings()->targetFps);
        $this->assertSame('source_b', $monitor->lastTriggerSource());
    }
}
