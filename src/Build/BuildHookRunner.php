<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Runs the build.json `hooks.beforeBuild` commands before a build stages the
 * game. They run once per build that creates a PHAR; targets that reuse a
 * shared PHAR (`--phar`) skip them, because the staged tree is already built.
 *
 *   "hooks": {
 *     "beforeBuild": [
 *       { "run": ["@php", "-d", "memory_limit=-1", "tools/prepare-assets.php"],
 *         "requires": ["imagettftext"], "runtimeVariant": "steam" },
 *       ["@php", "tools/other-step.php"]
 *     ]
 *   }
 *
 * A hook is a command array run in the project root; the short form is the
 * command array itself. `@php` as the first element names a PHP interpreter
 * that can run the hook:
 *
 *   1. PHPOLYGON_HOOK_PHP, when set;
 *   2. the PHP running the build, when every function listed in `requires`
 *      exists there;
 *   3. otherwise the game's static runtime (micro.sfx) for this host, variant
 *      `runtimeVariant` (default: the build variant), wrapped as a runner that
 *      takes `[-d key=value]... script.php [args...]`. A hook can so use the
 *      extensions the shipped game has, also inside a build container whose own
 *      PHP lacks them.
 *
 * PHPOLYGON_HOOK_PHP in the environment of an `@php` hook names its interpreter.
 * A hook starts further PHP processes with it: PHP_BINARY is empty inside a
 * micro runtime.
 *
 * The environment carries PHPOLYGON_BUILD_PLATFORM, PHPOLYGON_BUILD_ARCH,
 * PHPOLYGON_BUILD_VARIANT and PHPOLYGON_BUILD_TYPE. A failing hook fails the
 * build; PHPOLYGON_SKIP_HOOKS=1 skips all hooks.
 */
final class BuildHookRunner
{
    /**
     * Appended to a micro.sfx to turn it into a command-line interpreter for
     * hooks: `-d key=value` pairs become ini_set() calls, the next argument is
     * the script, the rest its $argv.
     */
    public const string RUNNER_BOOTSTRAP = <<<'PHP'
        <?php
        $args = $_SERVER['argv'] ?? [];
        array_shift($args);
        while (count($args) >= 2 && $args[0] === '-d') {
            [$key, $value] = array_pad(explode('=', $args[1], 2), 2, '1');
            ini_set($key, $value);
            $args = array_slice($args, 2);
        }
        if ($args === []) {
            fwrite(STDERR, "usage: runner [-d key=value]... script.php [args...]\n");
            exit(2);
        }
        $script = array_shift($args);
        $argv = [$script, ...$args];
        $argc = count($argv);
        $_SERVER['argv'] = $argv;
        $_SERVER['argc'] = $argc;
        $_SERVER['SCRIPT_FILENAME'] = $script;
        require $script;
        PHP;

    /** @var \Closure(string, string, string, string): string */
    private \Closure $runtimeResolver;

    /** @var \Closure(string, string, string, string): list<string> */
    private \Closure $runtimeLibsResolver;

    /** @var \Closure(string, string): void */
    private \Closure $logger;

    private string $runnerDir;

    /**
     * @param callable(string, string, string, string): string $runtimeResolver
     *        micro.sfx path for (platform, arch, variant, PHP version)
     * @param (callable(string, string, string, string): list<string>)|null $runtimeLibsResolver
     *        libraries the runtime loads from its own directory (Windows DLLs, the
     *        Steam API library), for the same arguments
     */
    public function __construct(
        private readonly BuildConfig $config,
        callable $runtimeResolver,
        ?string $runnerDir = null,
        ?callable $runtimeLibsResolver = null,
    ) {
        $this->runtimeResolver = \Closure::fromCallable($runtimeResolver);
        $this->runtimeLibsResolver = $runtimeLibsResolver !== null
            ? \Closure::fromCallable($runtimeLibsResolver)
            : static fn (string $platform, string $arch, string $variant, string $phpVersion): array => [];
        $this->logger = static function (string $level, string $message): void {};
        $this->runnerDir = $runnerDir ?? sys_get_temp_dir() . '/phpolygon-hook-runtime';
    }

    /** @param callable(string, string): void $logger fn(level, message) */
    public function setLogger(callable $logger): void
    {
        $this->logger = \Closure::fromCallable($logger);
    }

    /**
     * Run every beforeBuild hook in order.
     *
     * @param array{platform: string, arch: string, variant: string, type: string, phpVersion: string} $context
     * @throws \RuntimeException when a hook cannot start or exits non-zero
     */
    public function runBeforeBuild(array $context): void
    {
        if ($this->config->hooksBeforeBuild === []) {
            return;
        }
        if (getenv('PHPOLYGON_SKIP_HOOKS') === '1') {
            ($this->logger)('info', 'Skipping build hooks (PHPOLYGON_SKIP_HOOKS=1)');
            return;
        }

        $environment = [
            'PHPOLYGON_BUILD_PLATFORM' => $context['platform'],
            'PHPOLYGON_BUILD_ARCH' => $context['arch'],
            'PHPOLYGON_BUILD_VARIANT' => $context['variant'],
            'PHPOLYGON_BUILD_TYPE' => $context['type'],
        ] + getenv();

        foreach ($this->config->hooksBeforeBuild as $index => $hook) {
            $command = $hook['run'];
            $hookEnvironment = $environment;
            if ($command[0] === '@php') {
                $command[0] = $this->interpreter($hook, $context);
                $hookEnvironment['PHPOLYGON_HOOK_PHP'] = $command[0];
            }
            ($this->logger)('info', 'Build hook: ' . implode(' ', $hook['run']));
            $exit = $this->execute($command, $hookEnvironment);
            if ($exit !== 0) {
                throw new \RuntimeException(sprintf('Build hook #%d (%s) failed with exit code %d', $index + 1, implode(' ', $hook['run']), $exit));
            }
        }
    }

    /**
     * The PHP interpreter `@php` stands for in $hook (see the class comment).
     *
     * @param array{run: list<string>, requires: list<string>, runtimeVariant: ?string} $hook
     * @param array{platform: string, arch: string, variant: string, type: string, phpVersion: string} $context
     */
    public function interpreter(array $hook, array $context): string
    {
        $override = getenv('PHPOLYGON_HOOK_PHP');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $missing = array_values(array_filter($hook['requires'], static fn (string $function): bool => !function_exists($function)));
        if ($missing === []) {
            return PHP_BINARY;
        }

        $variant = $hook['runtimeVariant'] ?? $context['variant'];
        ($this->logger)('info', sprintf('Build PHP lacks %s: running the hook with the %s game runtime', implode(', ', $missing), $variant));
        $platform = self::hostPlatform();
        $arch = StaticPhpResolver::detectArch();
        $microSfx = ($this->runtimeResolver)($platform, $arch, $variant, $context['phpVersion']);

        return $this->runner($microSfx, ($this->runtimeLibsResolver)($platform, $arch, $variant, $context['phpVersion']));
    }

    /**
     * The micro.sfx at $microSfx with {@see RUNNER_BOOTSTRAP} appended, built once per
     * runtime file, with $libs next to it: the runtime loads them from its own
     * directory and does not start without them (0xC0000135 on Windows).
     *
     * @param list<string> $libs
     */
    public function runner(string $microSfx, array $libs = []): string
    {
        $size = filesize($microSfx);
        if ($size === false) {
            throw new \RuntimeException("Game runtime not readable: {$microSfx}");
        }
        $key = substr(md5($microSfx . '|' . $size . '|' . (int) filemtime($microSfx) . '|' . self::RUNNER_BOOTSTRAP), 0, 16);
        $path = $this->runnerDir . '/' . $key . '/php-runtime' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        if (!is_file($path)) {
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Cannot create ' . dirname($path));
            }
            $runtime = file_get_contents($microSfx);
            if ($runtime === false) {
                throw new \RuntimeException("Game runtime not readable: {$microSfx}");
            }
            $tmp = $path . '.tmp' . getmypid();
            file_put_contents($tmp, $runtime . self::RUNNER_BOOTSTRAP);
            chmod($tmp, 0755);
            rename($tmp, $path);
        }

        // Checked on every call, so a runner built before its libraries were known gets them too.
        foreach ($libs as $lib) {
            $target = dirname($path) . '/' . basename($lib);
            if (is_file($lib) && (!is_file($target) || filesize($target) !== filesize($lib)) && !copy($lib, $target)) {
                throw new \RuntimeException("Cannot copy runtime library {$lib} next to the hook runner");
            }
        }

        return $path;
    }

    /** Platform name of this host as the runtime releases use it. */
    public static function hostPlatform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };
    }

    /**
     * Run $command in the project root and relay its output line by line.
     *
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function execute(array $command, array $environment): int
    {
        // Both streams go to one log file that is relayed while the hook runs:
        // two pipes read one after the other can deadlock, and non-blocking
        // pipes do not work on Windows.
        $log = tempnam(sys_get_temp_dir(), 'phpolygon-hook-');
        if ($log === false) {
            throw new \RuntimeException('Cannot create a log file for the build hook');
        }
        try {
            // One handle for both streams: two handles on the same file overwrite
            // each other's output on Windows.
            $sink = fopen($log, 'ab');
            if ($sink === false) {
                throw new \RuntimeException('Cannot open the log file for the build hook');
            }
            $process = proc_open($command, [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => $sink,
                2 => $sink,
            ], $pipes, $this->config->projectRoot, $environment);
            fclose($sink);
            if (!is_resource($process)) {
                throw new \RuntimeException('Cannot start build hook: ' . implode(' ', $command));
            }

            $reader = fopen($log, 'rb');
            $pending = '';
            do {
                $status = proc_get_status($process);
                if ($reader !== false) {
                    $pending .= (string) stream_get_contents($reader);
                    while (($newline = strpos($pending, "\n")) !== false) {
                        ($this->logger)('hook', rtrim(substr($pending, 0, $newline), "\r"));
                        $pending = substr($pending, $newline + 1);
                    }
                }
                if ($status['running']) {
                    usleep(100_000);
                }
            } while ($status['running']);
            if ($pending !== '') {
                ($this->logger)('hook', rtrim($pending, "\r"));
            }
            if ($reader !== false) {
                fclose($reader);
            }
            proc_close($process);

            return $status['exitcode'];
        } finally {
            @unlink($log);
        }
    }
}
