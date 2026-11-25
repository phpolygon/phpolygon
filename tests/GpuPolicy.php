<?php

declare(strict_types=1);

namespace PHPolygon\Tests;

/**
 * Which adapter the render tests run on, and what the local machine may do about it.
 *
 * CI keeps the software adapter (llvmpipe, lavapipe, WARP): nothing here changes there.
 * Locally the tests never run on a software adapter. tests/bootstrap.php sets
 * VIO_D3D_HEADLESS_HARDWARE=1, which keeps every headless D3D context off WARP, and
 * {@see \PHPolygon\Tests\Rendering\VioTestContext} skips when the context it got is
 * a software one anyway. PHPOLYGON_TEST_SOFTWARE=1 lifts the rule for a deliberate run.
 *
 * Adapter classes name the references a test may compare against: 'software' (the CI adapter),
 * 'hardware-nvidia', 'hardware-amd', 'hardware-intel', 'hardware-apple', 'hardware-other'.
 */
final class GpuPolicy
{
    private static ?string $class = null;

    public static function isCi(): bool
    {
        foreach (['CI', 'GITHUB_ACTIONS'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '' && $value !== '0' && strtolower($value) !== 'false') {
                return true;
            }
        }

        return false;
    }

    /** True when software rendering is allowed: in CI, or when asked for with PHPOLYGON_TEST_SOFTWARE=1. */
    public static function softwareAllowed(): bool
    {
        return self::isCi() || getenv('PHPOLYGON_TEST_SOFTWARE') === '1';
    }

    /**
     * The class of the adapter a default headless context opens: 'software', 'hardware-<vendor>'
     * or 'none' (no context). The scripts probe once and hand the answer to the workers in
     * PHPOLYGON_TEST_ADAPTER_CLASS; without it the first call probes.
     */
    public static function adapterClass(): string
    {
        $env = getenv('PHPOLYGON_TEST_ADAPTER_CLASS');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::$class ??= self::probe();
    }

    /** The adapter class of an open context. */
    public static function classOf(\VioContext $ctx): string
    {
        $info = vio_backend_info($ctx);
        if (($info['device_type'] ?? '') === 'software') {
            return 'software';
        }
        $vendor = strtolower((string) ($info['vendor'] ?? ''));
        foreach (['nvidia', 'amd', 'intel', 'apple'] as $known) {
            if (str_contains($vendor, $known)) {
                return 'hardware-' . $known;
            }
        }

        return 'hardware-other';
    }

    /**
     * Picks the value of a per-adapter-class table: the entry of the current class, else the
     * 'default' entry, else null (the caller treats that as "no reference").
     *
     * @template T
     * @param array<string, T> $table
     * @return T|null
     */
    public static function reference(array $table): mixed
    {
        return $table[self::adapterClass()] ?? $table['default'] ?? null;
    }

    private static function probe(): string
    {
        if (!function_exists('vio_create')) {
            return 'none';
        }
        $config = ['width' => 16, 'height' => 16, 'headless' => true, 'vsync' => false];
        $adapter = getenv('PHPOLYGON_TEST_VIO_ADAPTER');
        if (is_string($adapter) && $adapter !== '') {
            $config['adapter'] = ctype_digit($adapter) ? (int) $adapter : $adapter;
        }
        $ctx = @vio_create(getenv('PHPOLYGON_TEST_VIO_BACKEND') ?: 'auto', $config);
        if ($ctx === false) {
            return 'none';
        }
        $class = self::classOf($ctx);
        vio_destroy($ctx);

        return $class;
    }
}
