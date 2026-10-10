<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Installs a game's production dependencies (no require-dev) into a working
 * directory of its own, never into the project.
 *
 * The working directory gets a copy of the project's composer.json and
 * composer.lock and runs `composer install --no-dev` there. The project's own
 * vendor/ and composer.lock are only read: a build no longer swaps the
 * development install out and back in, so a build that fails halfway, or
 * several builds running side by side, cannot leave the development tools
 * half-installed, and the locked versions are what ships (an install never
 * re-resolves them).
 *
 * The generated autoloader addresses the root package's files relative to the
 * working directory (`$baseDir . '/src'`). The PHAR places the vendor directory
 * next to the staged sources, so the same relative paths resolve inside the
 * archive. The root package's autoload paths are mirrored into the working
 * directory first, so the classmap and the optimised autoloader see them.
 */
final class ProductionVendor
{
    /** The command that runs Composer; overridden by PHPOLYGON_COMPOSER. */
    public const DEFAULT_COMPOSER = 'composer';

    private string $composerCommand;

    /** @var callable(string, string): void */
    private $logger;

    /**
     * @param ?string $composerCommand shell command prefix that runs Composer
     *   (e.g. `composer` or `"/usr/bin/php" "/opt/composer.phar"`), already escaped
     */
    public function __construct(
        private readonly string $projectRoot,
        ?string $composerCommand = null,
    ) {
        $env = getenv('PHPOLYGON_COMPOSER');
        $this->composerCommand = $composerCommand
            ?? (is_string($env) && $env !== '' ? $env : self::DEFAULT_COMPOSER);
        $this->logger = static function (string $level, string $message): void {
        };
    }

    /**
     * @param callable(string, string): void $logger fn(level, message)
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Install the production dependencies into $workDir and return its vendor
     * directory. $workDir is emptied first; the caller removes it afterwards.
     *
     * @throws \RuntimeException when the project has no composer.json or Composer fails
     */
    public function install(string $workDir): string
    {
        $composerJson = $this->projectRoot . '/composer.json';
        $raw = is_file($composerJson) ? file_get_contents($composerJson) : false;
        if ($raw === false) {
            throw new \RuntimeException("Cannot install production dependencies: no composer.json in {$this->projectRoot}");
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException("Cannot install production dependencies: {$composerJson} is not valid JSON");
        }

        FileTree::remove($workDir);
        if (!mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new \RuntimeException("Cannot create {$workDir}");
        }

        // composer.json byte for byte: the lock's content-hash is taken over it.
        file_put_contents($workDir . '/composer.json', $raw);

        $lockFile = $this->projectRoot . '/composer.lock';
        if (is_file($lockFile)) {
            $lock = file_get_contents($lockFile);
            if ($lock === false) {
                throw new \RuntimeException("Cannot read {$lockFile}");
            }
            file_put_contents($workDir . '/composer.lock', $this->absolutePathDists($lock));
        } else {
            ($this->logger)('warning', 'No composer.lock in the project - the production dependencies are resolved anew and may differ from the development install');
        }

        $this->mirrorAutoloadPaths($manifest, $workDir);

        $command = sprintf(
            '%s install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs --working-dir=%s 2>&1',
            $this->composerCommand,
            escapeshellarg($workDir),
        );
        [$exitCode, $output] = $this->run($command, $workDir);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                "Installing the production dependencies failed (composer install --no-dev, exit code %d).\n"
                . "The project's vendor/ and composer.lock are unchanged.\nComposer output:\n%s",
                $exitCode,
                rtrim($output),
            ));
        }

        $vendorDir = $workDir . '/vendor';
        if (!is_file($vendorDir . '/autoload.php')) {
            throw new \RuntimeException("composer install --no-dev reported success, but wrote no {$vendorDir}/autoload.php.\nComposer output:\n" . rtrim($output));
        }

        return $vendorDir;
    }

    /**
     * A path repository is locked with its URL as written in composer.json,
     * usually relative to the project ("../engine"). Relative to the working
     * directory it points nowhere, so the copy of the lock gets the absolute
     * path. Only dist/source entries of type "path" change; the content-hash
     * covers composer.json, not the package list, and stays valid.
     */
    private function absolutePathDists(string $lock): string
    {
        $data = json_decode($lock, true);
        if (!is_array($data)) {
            throw new \RuntimeException("{$this->projectRoot}/composer.lock is not valid JSON");
        }
        $changed = false;
        foreach (['packages', 'packages-dev'] as $section) {
            if (!isset($data[$section]) || !is_array($data[$section])) {
                continue;
            }
            $packages = $data[$section];
            foreach ($packages as $i => $package) {
                if (!is_array($package)) {
                    continue;
                }
                foreach (['dist', 'source'] as $kind) {
                    $ref = $package[$kind] ?? null;
                    if (!is_array($ref) || ($ref['type'] ?? null) !== 'path' || !is_string($ref['url'] ?? null)) {
                        continue;
                    }
                    $url = $ref['url'];
                    if ($this->isAbsolutePath($url)) {
                        continue;
                    }
                    $real = realpath($this->projectRoot . '/' . $url);
                    $ref['url'] = str_replace('\\', '/', $real !== false ? $real : $this->projectRoot . '/' . $url);
                    $package[$kind] = $ref;
                    $packages[$i] = $package;
                    $changed = true;
                }
            }
            $data[$section] = $packages;
        }

        return $changed
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            : $lock;
    }

    /**
     * Copy the root package's autoload paths (psr-4, psr-0, classmap, files)
     * into the working directory, so Composer can scan them for the classmap.
     * The copies only serve the scan; the PHAR stages the project's own files.
     *
     * @param array<mixed> $manifest
     */
    private function mirrorAutoloadPaths(array $manifest, string $workDir): void
    {
        $autoload = $manifest['autoload'] ?? null;
        if (!is_array($autoload)) {
            return;
        }
        $paths = [];
        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach ((array) ($autoload[$standard] ?? []) as $dirs) {
                foreach ((array) $dirs as $dir) {
                    $paths[] = $dir;
                }
            }
        }
        foreach (['classmap', 'files'] as $kind) {
            foreach ((array) ($autoload[$kind] ?? []) as $path) {
                $paths[] = $path;
            }
        }

        foreach (array_unique(array_filter($paths, 'is_string')) as $path) {
            $path = trim(str_replace('\\', '/', $path), '/');
            if ($path === '' || $path === '.' || $this->isAbsolutePath($path) || str_starts_with($path, '..')) {
                // The whole project, or outside it: nothing to mirror that the
                // archive could address relative to its root.
                continue;
            }
            $sources = str_contains($path, '*')
                ? (glob($this->projectRoot . '/' . $path) ?: [])
                : [$this->projectRoot . '/' . $path];
            foreach ($sources as $source) {
                $relative = substr(str_replace('\\', '/', $source), strlen(str_replace('\\', '/', $this->projectRoot)) + 1);
                $target = $workDir . '/' . $relative;
                if (is_dir($source)) {
                    FileTree::copy($source, $target);
                } elseif (is_file($source)) {
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0755, true);
                    }
                    copy($source, $target);
                }
            }
        }
    }

    /**
     * @return array{int, string} exit code and combined output
     */
    private function run(string $command, string $cwd): array
    {
        $env = getenv();
        // Point Composer at the working directory's own manifest and vendor/,
        // whatever the calling shell has set.
        unset($env['COMPOSER']);
        $env['COMPOSER_VENDOR_DIR'] = 'vendor';
        $env['COMPOSER_NO_INTERACTION'] = '1';
        $rootVersion = $this->rootVersion();
        if ($rootVersion !== null && !isset($env['COMPOSER_ROOT_VERSION'])) {
            $env['COMPOSER_ROOT_VERSION'] = $rootVersion;
        }

        ($this->logger)('info', 'Running ' . $command);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException("Cannot start Composer: {$command}");
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    /**
     * The root package's version as the development install recorded it, so the
     * copy (which has no VCS checkout to detect it from) reports the same.
     */
    private function rootVersion(): ?string
    {
        $file = $this->projectRoot . '/vendor/composer/installed.php';
        if (!is_file($file)) {
            return null;
        }
        try {
            $data = require $file;
        } catch (\Throwable) {
            return null;
        }
        $version = is_array($data) && is_array($data['root'] ?? null) ? ($data['root']['pretty_version'] ?? null) : null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
