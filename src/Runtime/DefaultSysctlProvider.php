<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Production SysctlProvider backed by shell_exec. macOS-only - returns null
 * on other platforms and when shell_exec is disabled via disable_functions
 * so HardwareProfiler can fall back cleanly to ThermalProfile::Unknown.
 */
final class DefaultSysctlProvider implements SysctlProvider
{
    public function read(string $key): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }
        if (!function_exists('shell_exec')) {
            return null;
        }
        $escaped = escapeshellarg($key);
        $result = @shell_exec("sysctl -n {$escaped} 2>/dev/null");
        if (!is_string($result)) {
            return null;
        }
        $trimmed = trim($result);
        return $trimmed === '' ? null : $trimmed;
    }
}
