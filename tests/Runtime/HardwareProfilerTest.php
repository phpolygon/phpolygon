<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\HardwareProfiler;
use PHPolygon\Runtime\SysctlProvider;
use PHPolygon\Runtime\ThermalProfile;

final class HardwareProfilerTest extends TestCase
{
    public function testHeadlessReturnsUnknown(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([
            'machdep.cpu.brand_string' => 'Intel(R) Core(TM) i9-9980HK CPU @ 2.40GHz',
        ]));
        $profile = $profiler->detect(headless: true);
        $this->assertSame(ThermalProfile::Unknown, $profile->thermalProfile);
    }

    /**
     * @requires OSFAMILY Darwin
     */
    public function testDetectsI9_9980HKAs2018_15inch(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([
            'machdep.cpu.brand_string' => 'Intel(R) Core(TM) i9-9980HK CPU @ 2.40GHz',
        ]));
        $profile = $profiler->detect(headless: false);
        $this->assertSame(ThermalProfile::IntelI9_2018_15inch, $profile->thermalProfile);
        $this->assertSame('Intel', $profile->vendor);
        $this->assertSame(50.0, $profile->targetFpsCeiling());
    }

    /**
     * @requires OSFAMILY Darwin
     */
    public function testDetectsI9_8950HKAs2018_15inch(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([
            'machdep.cpu.brand_string' => 'Intel(R) Core(TM) i9-8950HK CPU @ 2.90GHz',
        ]));
        $profile = $profiler->detect(headless: false);
        $this->assertSame(ThermalProfile::IntelI9_2018_15inch, $profile->thermalProfile);
        $this->assertSame(50.0, $profile->targetFpsCeiling());
    }

    /**
     * @requires OSFAMILY Darwin
     */
    public function testDetectsI9_9880HAs2019_15inch(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([
            'machdep.cpu.brand_string' => 'Intel(R) Core(TM) i9-9880H CPU @ 2.30GHz',
        ]));
        $profile = $profiler->detect(headless: false);
        $this->assertSame(ThermalProfile::IntelI9_2018_15inch, $profile->thermalProfile);
        $this->assertSame(50.0, $profile->targetFpsCeiling());
    }

    /**
     * @requires OSFAMILY Darwin
     */
    public function testDetectsGenericI9(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([
            'machdep.cpu.brand_string' => 'Intel(R) Core(TM) i9-12900K CPU @ 3.20GHz',
        ]));
        $profile = $profiler->detect(headless: false);
        $this->assertSame(ThermalProfile::IntelI9, $profile->thermalProfile);
        $this->assertSame(60.0, $profile->targetFpsCeiling());
    }

    /**
     * @requires OSFAMILY Darwin
     */
    public function testDetectsAppleSiliconAsNoCeiling(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([
            'machdep.cpu.brand_string' => 'Apple M1 Pro',
        ]));
        $profile = $profiler->detect(headless: false);
        $this->assertSame(ThermalProfile::AppleSilicon, $profile->thermalProfile);
        $this->assertSame('Apple', $profile->vendor);
        $this->assertNull($profile->targetFpsCeiling());
    }

    /**
     * @requires OSFAMILY Darwin
     */
    public function testDetectsRegularIntelAsNoCeiling(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([
            'machdep.cpu.brand_string' => 'Intel(R) Core(TM) i5-1038NG7 CPU @ 2.00GHz',
        ]));
        $profile = $profiler->detect(headless: false);
        $this->assertSame(ThermalProfile::IntelCore, $profile->thermalProfile);
        $this->assertNull($profile->targetFpsCeiling());
    }

    /**
     * @requires OSFAMILY Darwin
     */
    public function testEmptyBrandStringFallsBackToUnknown(): void
    {
        $profiler = new HardwareProfiler(new StubSysctlProvider([]));
        $profile = $profiler->detect(headless: false);
        $this->assertSame(ThermalProfile::Unknown, $profile->thermalProfile);
    }

    public function testRealHostDoesNotThrow(): void
    {
        // Smoke: real sysctl on the test host either returns a profile or
        // Unknown - both are fine, just verify the chain doesn't blow up
        // and that we land in one of the known profiles.
        $profiler = new HardwareProfiler();
        $profile = $profiler->detect();
        $this->assertContains($profile->thermalProfile, ThermalProfile::cases());
    }
}

final class StubSysctlProvider implements SysctlProvider
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function read(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }
}
