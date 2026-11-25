<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Locally the render tests run on the hardware GPU, never on a software adapter. php-vio's D3D
// backends read VIO_D3D_HEADLESS_HARDWARE from the process environment at start-up (putenv() from PHP
// does not reach them), and with it set every headless D3D11 / D3D12 context stays off WARP, also the
// ones tests open with vio_create() themselves. So when the variable is missing, this bootstrap starts
// the same command again with it set and returns that command's exit code; the scripts (scripts/test.*)
// set it themselves and never get here. CI (CI / GITHUB_ACTIONS set) keeps its software adapter,
// PHPOLYGON_TEST_SOFTWARE=1 asks for it locally. See PHPolygon\Tests\GpuPolicy.
if (
    PHP_OS_FAMILY === 'Windows'
    && function_exists('vio_create')
    && !\PHPolygon\Tests\GpuPolicy::softwareAllowed()
    && getenv('VIO_D3D_HEADLESS_HARDWARE') === false
) {
    $env = getenv();
    $env['VIO_D3D_HEADLESS_HARDWARE'] = '1';
    $command = [PHP_BINARY, '-d', 'memory_limit=' . ini_get('memory_limit'), ...$_SERVER['argv']];
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
    if (is_resource($process)) {
        exit(proc_close($process));
    }
}
