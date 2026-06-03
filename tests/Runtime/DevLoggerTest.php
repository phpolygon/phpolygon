<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Quality\PressureSignal;
use PHPolygon\Runtime\DevLogger;
use PHPolygon\Runtime\HardwareProfile;
use PHPolygon\Runtime\ThermalProfile;

final class DevLoggerTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir() . '/phpolygon_devlog_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        parent::tearDown();
    }

    public function testLogsHardwareProfile(): void
    {
        $logger = new DevLogger($this->tmpPath, alsoStdout: false);
        $profile = new HardwareProfile(
            vendor: 'Intel',
            cpuBrand: 'i9-9980HK',
            osFamily: 'Darwin',
            thermalProfile: ThermalProfile::IntelI9_2018_15inch,
        );
        $logger->logHardwareProfile($profile);
        unset($logger);

        $contents = $this->readLog();
        $this->assertStringContainsString('[HW]', $contents);
        $this->assertStringContainsString('i9-9980HK', $contents);
    }

    public function testLogsStateChange(): void
    {
        $logger = new DevLogger($this->tmpPath, alsoStdout: false);
        $logger->logStateChange(PressureSignal::Nominal, PressureSignal::Serious, 'thermal_macos');
        unset($logger);

        $contents = $this->readLog();
        $this->assertStringContainsString('[THERMAL]', $contents);
        $this->assertStringContainsString('nominal -> serious', $contents);
        $this->assertStringContainsString('thermal_macos', $contents);
    }

    public function testLogsTargetFpsChange(): void
    {
        $logger = new DevLogger($this->tmpPath, alsoStdout: false);
        $logger->logTargetFpsChange(60.0, 45.0, 'frametime_guard', 'pressure=serious');
        unset($logger);

        $contents = $this->readLog();
        $this->assertStringContainsString('[TGT]', $contents);
        $this->assertStringContainsString('60 -> 45 fps', $contents);
    }

    public function testFrametimeLoggingIsThrottled(): void
    {
        $logger = new DevLogger($this->tmpPath, alsoStdout: false);
        // First call should write; immediate second call within 5 s window must not.
        $logger->logFrameTime(20.0, 16.67, 60.0);
        $logger->logFrameTime(21.0, 16.67, 60.0);
        unset($logger);

        $contents = $this->readLog();
        $matches = preg_match_all('/\[P95\]/', $contents);
        $this->assertSame(1, $matches);
    }

    private function readLog(): string
    {
        $contents = file_get_contents($this->tmpPath);
        $this->assertNotFalse($contents);
        return $contents;
    }
}
