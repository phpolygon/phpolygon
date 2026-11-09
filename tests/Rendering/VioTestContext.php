<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

/**
 * The headless context of a render test. `auto` by default, which is the software
 * adapter on Windows; PHPOLYGON_TEST_VIO_BACKEND picks a backend (d3d12, vulkan, metal)
 * and PHPOLYGON_TEST_VIO_HARDWARE=1 asks for the hardware GPU. PHPOLYGON_TEST_VIO_ADAPTER
 * picks one adapter by part of its name or its position in vio_adapters() (php-vio's
 * 'adapter' option, e.g. a second GPU so two suites run side by side). Null when the
 * context does not open.
 */
final class VioTestContext
{
    /** @param array<string, mixed> $options */
    public static function create(int $width, int $height, array $options = []): ?\VioContext
    {
        // Without ext-vio (the unit jobs of CI) there is no context: the test skips.
        if (!function_exists('vio_create')) {
            return null;
        }
        $backend = getenv('PHPOLYGON_TEST_VIO_BACKEND') ?: 'auto';
        $config = ['width' => $width, 'height' => $height, 'headless' => true, 'vsync' => false] + $options;
        if (getenv('PHPOLYGON_TEST_VIO_HARDWARE') === '1') {
            $config['headless_hardware'] = true;
        }
        $adapter = getenv('PHPOLYGON_TEST_VIO_ADAPTER');
        if (is_string($adapter) && $adapter !== '') {
            $config['adapter'] = ctype_digit($adapter) ? (int) $adapter : $adapter;
        }
        $ctx = @vio_create($backend, $config);

        return $ctx === false ? null : $ctx;
    }
}
