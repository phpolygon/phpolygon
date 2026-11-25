<?php

// Loaded by tests/bootstrap.php when PHPOLYGON_TEST_VIO_ADAPTER names an adapter.
//
// php-vio opens the adapter named in vio_create()'s 'adapter' option (it has no environment variable for
// it), and most tests call vio_create() themselves. An unqualified call inside a namespace resolves to a
// function of that namespace first, so one shim per namespace that calls vio_create() adds the option to
// every call that sets none, without touching the call sites. Backends without a choice of adapter
// (null, OpenGL) are passed through unchanged. Keep the list in step with
// `git grep -l "vio_create(" -- tests src`.

declare(strict_types=1);

namespace PHPolygon\Tests {
    /**
     * @param array<string, mixed> $options
     */
    function adapter_options(string $backend, array $options): array
    {
        $adapter = getenv('PHPOLYGON_TEST_VIO_ADAPTER');
        if (is_string($adapter) && $adapter !== '' && !isset($options['adapter'])
            && !in_array($backend, ['null', 'opengl'], true)) {
            $options['adapter'] = ctype_digit($adapter) ? (int) $adapter : $adapter;
        }

        return $options;
    }
}

namespace PHPolygon\Rendering {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}

namespace PHPolygon\Runtime {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}

namespace PHPolygon\Testing\Shader {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}

namespace PHPolygon\Tests\Rendering {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}

namespace PHPolygon\Tests\Rendering\Quality {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}

namespace PHPolygon\Tests\Runtime {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}

namespace PHPolygon\Tests\System {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}

namespace PHPolygon\Tests\UI\Accessibility {
    /** @param array<string, mixed> $options */
    function vio_create(string $backend = 'auto', array $options = []): \VioContext|false
    {
        return \vio_create($backend, \PHPolygon\Tests\adapter_options($backend, $options));
    }
}
