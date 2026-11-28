<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Asks a game runtime (micro.sfx) which php-vio it contains by running it. That
 * is the only reliable source: the version string inside the binary has no marker
 * that holds across compilers. So only a runtime of the build host can answer.
 */
final class RuntimeProbe
{
    private const string SCRIPT = '<?php echo "phpolygon-vio=", phpversion("vio") ?: "none", "\n";';

    private const string PATTERN = '/phpolygon-vio=([0-9A-Za-z.+-]+)/';

    public function __construct(
        private readonly ?string $hostPlatform = null,
        private readonly ?string $hostArch = null,
        private readonly float $timeout = 30.0,
    ) {}

    /**
     * One line for the build log: the php-vio version of the runtime, or why it
     * cannot be told.
     *
     * @param list<string> $libs libraries the runtime loads from its own directory
     */
    public function describe(string $microSfx, array $libs, string $platform, string $arch): string
    {
        $hostPlatform = $this->hostPlatform ?? BuildHookRunner::hostPlatform();
        $hostArch = $this->hostArch ?? StaticPhpResolver::detectArch();
        if ($platform !== $hostPlatform || $arch !== $hostArch) {
            return "php-vio version unknown: a {$platform}-{$arch} runtime cannot run on this {$hostPlatform}-{$hostArch} host, and the release states no version";
        }
        [$version, $problem] = $this->run($microSfx, $libs);
        return match (true) {
            $version === null => "php-vio version unknown: {$problem}",
            $version === 'none' => 'the runtime contains no php-vio',
            default => "php-vio {$version} (reported by the runtime)",
        };
    }

    /**
     * @param list<string> $libs
     * @return array{?string, string} version or null, and why there is none
     */
    private function run(string $microSfx, array $libs): array
    {
        $dir = sys_get_temp_dir() . '/phpolygon-runtime-probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0755, true)) {
            return [null, "cannot create {$dir}"];
        }
        try {
            $runtime = $dir . '/probe' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (!@copy($microSfx, $runtime) || file_put_contents($runtime, self::SCRIPT, FILE_APPEND) === false) {
                return [null, 'cannot copy the runtime'];
            }
            chmod($runtime, 0755);
            foreach ($libs as $lib) {
                if (is_file($lib)) {
                    @copy($lib, $dir . '/' . basename($lib));
                }
            }

            $log = $dir . '/output.log';
            $sink = fopen($log, 'ab');
            if ($sink === false) {
                return [null, 'cannot open a log file'];
            }
            $environment = BuildHookRunner::withoutWindowSystem(BuildHookRunner::withLibraryDirectory(getenv(), $dir));
            $process = @proc_open([$runtime], [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => $sink,
                2 => $sink,
            ], $pipes, $dir, $environment);
            fclose($sink);
            if (!is_resource($process)) {
                return [null, 'the runtime did not start'];
            }
            $deadline = microtime(true) + $this->timeout;
            while (($status = proc_get_status($process))['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    proc_close($process);
                    return [null, 'the runtime did not answer in time'];
                }
                usleep(20_000);
            }
            proc_close($process);

            if (preg_match(self::PATTERN, (string) file_get_contents($log), $match) === 1) {
                return [$match[1], ''];
            }
            return [null, "the runtime did not answer (exit code {$status['exitcode']})"];
        } finally {
            try {
                FileTree::remove($dir);
            } catch (\RuntimeException) {
                // Windows can hold the exe for a moment; a leftover temp dir is harmless.
            }
        }
    }
}
