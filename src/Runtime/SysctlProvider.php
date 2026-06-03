<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/**
 * Reads a value from macOS sysctl. Abstracted so HardwareProfiler can be
 * unit-tested with deterministic CPU brand strings instead of whatever the
 * test host returns.
 */
interface SysctlProvider
{
    /**
     * Return the value of the given sysctl key, or null when sysctl is
     * unavailable (non-macOS, shell_exec disabled, key missing).
     */
    public function read(string $key): ?string;
}
