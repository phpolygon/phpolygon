<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

/**
 * Runtime detection for PHP parallel extension and CPU capabilities.
 */
final class ParallelCapability
{
    /**
     * Check if the parallel extension is available (requires ZTS PHP).
     */
    public static function isAvailable(): bool
    {
        return \PHP_ZTS && extension_loaded('parallel');
    }

    /**
     * Detect the number of logical CPU cores.
     */
    public static function getCpuCount(): int
    {
        // Linux
        if (is_file('/proc/cpuinfo')) {
            $content = file_get_contents('/proc/cpuinfo');
            if ($content !== false) {
                $count = substr_count($content, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // macOS / BSD
        if (\PHP_OS_FAMILY === 'Darwin') {
            $result = shell_exec('sysctl -n hw.ncpu');
            if ($result !== null && $result !== false) {
                $count = (int) trim($result);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // Windows
        $envCores = $_SERVER['NUMBER_OF_PROCESSORS'] ?? $_ENV['NUMBER_OF_PROCESSORS'] ?? null;
        if (is_string($envCores) || is_int($envCores)) {
            $count = (int) $envCores;
            if ($count > 0) {
                return $count;
            }
        }

        return 4; // safe fallback
    }

    /**
     * Recommended number of worker threads (reserves 1 core for OS/main thread).
     */
    public static function getRecommendedThreadCount(): int
    {
        return min(self::getCpuCount() - 1, 8);
    }

    /**
     * Determine the threading mode based on config and runtime capabilities.
     */
    public static function resolveMode(?ThreadingMode $requested): ThreadingMode
    {
        if ($requested !== null) {
            return $requested;
        }

        return self::isAvailable() ? ThreadingMode::MultiThreaded : ThreadingMode::SingleThreaded;
    }
}
