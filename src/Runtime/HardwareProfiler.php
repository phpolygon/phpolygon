<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Identifies the host CPU once at engine startup. Detection is conservative:
 * anything that isn't clearly a known throttle-prone i9 falls back to
 * ThermalProfile::IntelCore (no ceiling) or Unknown.
 *
 * The SysctlProvider is injectable so unit tests can feed canned brand
 * strings.
 */
final class HardwareProfiler
{
    public function __construct(
        private readonly SysctlProvider $sysctl = new DefaultSysctlProvider(),
    ) {
    }

    public function detect(bool $headless = false): HardwareProfile
    {
        $os = PHP_OS_FAMILY;

        if ($headless || $os !== 'Darwin') {
            return new HardwareProfile(
                vendor: 'unknown',
                cpuBrand: '',
                osFamily: $os,
                thermalProfile: ThermalProfile::Unknown,
            );
        }

        $brand = $this->sysctl->read('machdep.cpu.brand_string') ?? '';
        if ($brand === '') {
            return new HardwareProfile(
                vendor: 'unknown',
                cpuBrand: '',
                osFamily: $os,
                thermalProfile: ThermalProfile::Unknown,
            );
        }

        if (str_contains($brand, 'Apple')) {
            return new HardwareProfile(
                vendor: 'Apple',
                cpuBrand: $brand,
                osFamily: $os,
                thermalProfile: ThermalProfile::AppleSilicon,
            );
        }

        if (!str_contains($brand, 'Intel')) {
            return new HardwareProfile(
                vendor: self::guessVendor($brand),
                cpuBrand: $brand,
                osFamily: $os,
                thermalProfile: ThermalProfile::Unknown,
            );
        }

        // Known 2018/2019 15" MBP i9 chips. All three share the same
        // chassis + cooling system that famously throttles under load:
        //   - i9-8950HK: 2018 15" MBP (6 cores)
        //   - i9-9880H:  2019 15" MBP (8 cores)
        //   - i9-9980HK: 2019 15" MBP (8 cores, unlocked)
        if (preg_match('/i9-(8950HK|9880H|9980HK)\b/', $brand) === 1) {
            return new HardwareProfile(
                vendor: 'Intel',
                cpuBrand: $brand,
                osFamily: $os,
                thermalProfile: ThermalProfile::IntelI9_2018_15inch,
            );
        }

        if (str_contains($brand, 'i9')) {
            return new HardwareProfile(
                vendor: 'Intel',
                cpuBrand: $brand,
                osFamily: $os,
                thermalProfile: ThermalProfile::IntelI9,
            );
        }

        return new HardwareProfile(
            vendor: 'Intel',
            cpuBrand: $brand,
            osFamily: $os,
            thermalProfile: ThermalProfile::IntelCore,
        );
    }

    private static function guessVendor(string $brand): string
    {
        if (str_contains($brand, 'AMD'))   return 'AMD';
        if (str_contains($brand, 'ARM'))   return 'ARM';
        return 'unknown';
    }
}
