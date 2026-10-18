<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use Phar;

class PharBuilder
{
    /**
     * Paths under vendor/ that never belong in a release, matched against the
     * whole vendor-relative path (a `*` stays inside one segment): Composer's
     * leftover archives from interrupted downloads, the development files in
     * any package's root - which a path-repo checkout carries in full - and the
     * engine's own tooling directories. License files are always kept
     * ({@see isLicenseFile()}), since the packages' licenses require shipping them.
     */
    public const VENDOR_CLEANUP = [
        'composer/tmp-*',
        '*/*/.*',
        '*/*/*.md',
        '*/*/composer.lock',
        '*/*/phpbench.json',
        '*/*/phpstan*.neon*',
        '*/*/phpunit.xml*',
        '*/*/psalm*.xml*',
        '*/*/rector.php',
        'phpolygon/phpolygon/docker',
        'phpolygon/phpolygon/scripts',
        'phpolygon/phpolygon/stubs',
        'phpolygon/phpolygon/tools',
        'phpolygon/phpolygon/website',
        // bin/svg2mesh's sidecars; the generated PHP classes next to them ship.
        'phpolygon/phpolygon/src/Geometry/Generated/*.json',
    ];

    /**
     * File in the PHAR root that holds the build id: a hash of everything the
     * stub extracts (assets/, resources/). The stub carries the same id and
     * extracts only when the id on disk differs ({@see BundleExtractor}).
     */
    public const BUILD_ID_FILE = '.phpolygon-build-id';

    /**
     * The first comment line of every stub ({@see generateStub()}). A combined
     * binary's PHAR starts at the `<?php` right before it ({@see BuildVerifier}).
     */
    public const STUB_MARKER = '// __FILE__ is the archive:';

    private BuildConfig $config;

    private ?PathGlob $excludeGlob = null;

    public function __construct(BuildConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Create staging directory with resolved symlinks and filtered content
     *
     * @param ?string $vendorDir the vendor directory to stage, e.g. the production
     *   install from {@see ProductionVendor}; null stages the project's own vendor/
     */
    public function stage(string $stagingDir, ?string $vendorDir = null): void
    {
        if (is_dir($stagingDir)) {
            FileTree::remove($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        $projectRoot = $this->config->projectRoot;

        // Stage vendor/ with symlink resolution and exclude filtering
        $vendorSrc = $vendorDir ?? $projectRoot . '/vendor';
        $vendorDst = $stagingDir . '/vendor';
        if (is_dir($vendorSrc)) {
            $this->copyDirectoryFiltered($vendorSrc, $vendorDst, $this->config->pharExclude);
        }

        // Stage src/
        $srcDir = $projectRoot . '/src';
        if (is_dir($srcDir)) {
            FileTree::copy($srcDir, $stagingDir . '/src');
        }

        // Stage root PHP files and config
        $rootFiles = ['game.php', 'bootstrap.php'];
        foreach ($rootFiles as $file) {
            $path = $projectRoot . '/' . $file;
            if (file_exists($path)) {
                copy($path, $stagingDir . '/' . $file);
            }
        }

        // Stage entry file if different from defaults
        $entry = $this->config->entry;
        if (!in_array($entry, $rootFiles) && file_exists($projectRoot . '/' . $entry)) {
            copy($projectRoot . '/' . $entry, $stagingDir . '/' . $entry);
        }

        // Stage additional requires (e.g. bootstrap_constants.php, helpers)
        foreach ($this->config->additionalRequires as $require) {
            $srcPath = $projectRoot . '/' . $require;
            if (file_exists($srcPath)) {
                $dstPath = $stagingDir . '/' . $require;
                @mkdir(dirname($dstPath), 0755, true);
                copy($srcPath, $dstPath);
            }
        }

        // Stage resources/ and assets/: everything except the external paths
        // (they ship loose next to the executable) and resources.exclude.
        $this->stageProjectTree('resources', $stagingDir);

        // Stage mesh cache if present
        $meshCacheDir = $projectRoot . '/.phpolygon/mesh-cache';
        if (is_dir($meshCacheDir)) {
            FileTree::copy($meshCacheDir, $stagingDir . '/resources/meshes');
        }

        $this->stageProjectTree('assets', $stagingDir);
    }

    /**
     * Whether a project-relative path stays out of the PHAR: it is, or lies
     * below, a build.json `resources.external` path, or it matches
     * `resources.exclude`. A bare external name ("shaders") also means
     * resources/<name>, as it always did.
     */
    public function isLeftOutOfPhar(string $projectPath): bool
    {
        $projectPath = trim(str_replace('\\', '/', $projectPath), '/');
        foreach ($this->config->externalResources as $external) {
            $candidates = [$external];
            if (!str_contains($external, '/')) {
                $candidates[] = 'resources/' . $external;
            }
            foreach ($candidates as $candidate) {
                if ($projectPath === $candidate || str_starts_with($projectPath, $candidate . '/')) {
                    return true;
                }
            }
        }
        return $this->excludeGlob()->matches($projectPath);
    }

    /**
     * Drop every staged file under assets/ and resources/ that
     * {@see isLeftOutOfPhar()}: for files a build step created after staging,
     * such as the compiled `*.mesh.bin` of `assets.meshes = binary`, so
     * `resources.exclude` (e.g. a `*.mesh.bin` glob or a debug folder) holds
     * for them too.
     *
     * @return int number of files removed
     */
    public function pruneExcluded(string $stagingDir): int
    {
        $removed = 0;
        foreach (['assets', 'resources'] as $root) {
            $dir = $stagingDir . '/' . $root;
            if (!is_dir($dir)) {
                continue;
            }
            $doomed = [];
            foreach (FileTree::walk($dir) as $relative => $item) {
                if (!$item->isDir() && $this->isLeftOutOfPhar($root . '/' . $relative)) {
                    $doomed[] = $item->getPathname();
                }
            }
            foreach ($doomed as $path) {
                if (!@unlink($path)) {
                    throw new \RuntimeException("Cannot remove {$path}");
                }
                $removed++;
            }
        }
        return $removed;
    }

    private function excludeGlob(): PathGlob
    {
        return $this->excludeGlob ??= new PathGlob($this->config->resourcesExclude);
    }

    /** Copy the project folder $root into the staging tree, minus {@see isLeftOutOfPhar()}. */
    private function stageProjectTree(string $root, string $stagingDir): void
    {
        $src = $this->config->projectRoot . '/' . $root;
        if (!is_dir($src) || $this->isLeftOutOfPhar($root)) {
            return;
        }
        FileTree::copyFiltered(
            $src,
            $stagingDir . '/' . $root,
            fn (string $relative): bool => $this->isLeftOutOfPhar($root . '/' . $relative),
        );
    }

    /**
     * Create PHAR from staged directory
     */
    public function build(string $stagingDir, string $pharPath): void
    {
        if (file_exists($pharPath)) {
            unlink($pharPath);
        }

        $compress = $this->config->pharCompression === BuildConfig::PHAR_COMPRESSION_GZIP;
        if ($compress && !Phar::canCompress(Phar::GZ)) {
            throw new \RuntimeException(
                'build.json phar.compression is "gzip", but the PHP running the build has no zlib extension. '
                . 'Enable zlib or set "phar": {"compression": "none"}.'
            );
        }

        $buildId = self::computeBuildId($stagingDir);
        file_put_contents($stagingDir . '/' . self::BUILD_ID_FILE, $buildId);

        // No alias: the stub addresses the archive as phar://__FILE__, and an alias
        // would keep the build process from opening a second copy of the same
        // archive (a reused --phar next to the one just built) to pre-extract it.
        $phar = new Phar($pharPath);
        $phar->startBuffering();
        $phar->buildFromDirectory($stagingDir);
        $phar->setStub($this->generateStub($buildId));
        if ($compress) {
            // Every entry, gzip per file: the archive shrinks to a fraction (meshes
            // as JSON, BC7 texture data), while the stub's start-up extraction costs
            // the same - inflating is paid back by reading far fewer bytes. The stub
            // itself stays plain, so it can still refuse a runtime without zlib.
            $phar->compressFiles(Phar::GZ);
        }
        $phar->stopBuffering();
    }

    /**
     * Turn a built PHAR into the one of another build type: copy it and rewrite
     * the given constants in its bootstrap files ({@see BuildConstants}). Build
     * types differ only there, so hooks, the production install, staging and
     * compressing the archive run once per release instead of once per type.
     * Assets and resources are the same, so is the build id. Needs phar.readonly=0.
     *
     * @param array<string, mixed> $constants every constant the target type must
     *   carry, also those it leaves at the project's value (the source may be another type)
     */
    public function deriveBuildType(string $sourcePhar, string $targetPhar, array $constants): void
    {
        if (!copy($sourcePhar, $targetPhar)) {
            throw new \RuntimeException("cannot copy {$sourcePhar} to {$targetPhar}");
        }
        if ($constants === []) {
            return;
        }
        $base = 'phar://' . str_replace('\\', '/', $targetPhar);
        $work = sys_get_temp_dir() . '/phpolygon-derive-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($work, 0755, true);
        try {
            $files = [];
            foreach ([BuildConstants::BOOTSTRAP, ...$this->config->additionalRequires] as $file) {
                $file = ltrim(str_replace('\\', '/', $file), '/');
                $content = @file_get_contents($base . '/' . $file);
                if (is_string($content) && !in_array($file, $files, true)) {
                    @mkdir(dirname($work . '/' . $file), 0755, true);
                    file_put_contents($work . '/' . $file, $content);
                    $files[] = $file;
                }
            }
            $applied = BuildConstants::apply($work, $constants, $files);
            $changed = array_values(array_unique(array_merge(...array_values($applied))));

            $phar = new Phar($targetPhar);
            $phar->startBuffering();
            foreach ($changed as $file) {
                $phar->addFromString($file, (string) file_get_contents($work . '/' . $file));
                if ($this->config->pharCompression === BuildConfig::PHAR_COMPRESSION_GZIP) {
                    $phar[$file]->compress(Phar::GZ);
                }
            }
            $phar->stopBuffering();
            unset($phar);
        } finally {
            FileTree::remove($work);
        }
    }

    /**
     * Hash of everything the stub extracts (staged assets/ and resources/):
     * paths, sizes and contents. Two builds with the same files get the same id,
     * so an update that only changes code extracts nothing on the next start.
     */
    public static function computeBuildId(string $stagingDir): string
    {
        $context = hash_init('sha256');
        foreach (['assets', 'resources'] as $root) {
            $dir = $stagingDir . '/' . $root;
            if (!is_dir($dir)) {
                continue;
            }
            $entries = [];
            foreach (FileTree::walk($dir) as $relative => $item) {
                if (!$item->isDir()) {
                    $entries[$relative] = $item->getPathname();
                }
            }
            ksort($entries, SORT_STRING);
            foreach ($entries as $relative => $path) {
                hash_update($context, $root . '/' . $relative . "\0" . filesize($path) . "\0" . hash_file('xxh128', $path) . "\n");
            }
        }

        return substr(hash_final($context), 0, 32);
    }

    /** The build id a built PHAR carries ({@see BUILD_ID_FILE}); null for an older PHAR without one. */
    public static function readBuildId(string $pharPath): ?string
    {
        $id = @file_get_contents('phar://' . str_replace('\\', '/', $pharPath) . '/' . self::BUILD_ID_FILE);

        return is_string($id) && preg_match('/^[0-9a-f]{8,64}$/', $id) === 1 ? $id : null;
    }

    /**
     * The PHP payload appended to the macOS runtime when the PHAR ships as its
     * own file in Contents/Resources (one PHAR for the arm64 and the x86_64
     * binary of a universal bundle). It writes ~/Library/Logs/<name>/boot.log
     * (the previous start kept as boot.log.1) before anything else - a start that dies before
     * the game's own log is then still visible - and runs the PHAR.
     */
    public function generateMacLauncher(string $pharFile): string
    {
        $name = var_export($this->config->name, true);
        $phar = var_export($pharFile, true);

        return <<<PHP
<?php
\$__bin = PHP_BINARY ?: __FILE__;
\$__phar = dirname(\$__bin, 2) . '/Resources/' . {$phar};
\$__home = (string) getenv('HOME');
\$__logDir = \$__home !== '' ? \$__home . '/Library/Logs/' . {$name} : sys_get_temp_dir();
if (!is_dir(\$__logDir)) { @mkdir(\$__logDir, 0755, true); }
\$__log = static function (string \$msg) use (\$__logDir): void {
    @file_put_contents(\$__logDir . '/boot.log', '[' . gmdate('Y-m-d H:i:s') . '] ' . \$msg . "\\n", FILE_APPEND);
};
if (is_file(\$__logDir . '/boot.log')) { @rename(\$__logDir . '/boot.log', \$__logDir . '/boot.log.1'); }
\$__log('launcher: ' . \$__bin . ' (' . php_uname('m') . ', PHP ' . PHP_VERSION . ')');
\$__log('archive: ' . \$__phar);
if (!is_file(\$__phar)) {
    \$__log('FATAL: archive missing');
    if (defined('STDERR')) { fwrite(STDERR, "Game archive missing: {\$__phar}\\n"); }
    exit(1);
}
if (!extension_loaded('phar')) {
    \$__log('FATAL: the PHP runtime has no phar extension');
    exit(1);
}
require \$__phar;

PHP;
    }

    /**
     * Generate the PHAR stub.
     * Handles micro SAPI detection, macOS .app bundles, PHPolygon path setup,
     * resource extraction, and engine bootstrap.
     *
     * @param string $buildId id of the files the stub extracts, see {@see computeBuildId()}
     */
    public function generateStub(string $buildId = 'dev'): string
    {
        $additionalRequires = '';
        foreach ($this->config->additionalRequires as $require) {
            $additionalRequires .= "\n\$__engineLog('Requiring: {$require}');";
            $additionalRequires .= "\nrequire_once \$pharBase . '/{$require}';";
        }

        $runCode = '';
        if ($this->config->run !== '') {
            $runCode = "\n" . $this->config->run;
        }

        // A compressed archive can only be read with zlib. Without it every
        // include below fails with an opaque phar error, so the stub checks first
        // and says what is wrong - in game.log, where a player's report starts.
        $zlibGuard = $this->config->pharCompression === BuildConfig::PHAR_COMPRESSION_GZIP
            ? 'if (!extension_loaded(\'zlib\')) {'
                . "\n    \$__engineLog('FATAL: this build stores its files gzip-compressed, but the PHP runtime has no zlib extension.');"
                . "\n    if (defined('STDERR')) { fwrite(STDERR, \"This build needs a PHP runtime with the zlib extension.\\n\"); }"
                . "\n    exit(1);"
                . "\n}"
            : '';

        $placeholders = [
            '/*@@ZLIB_GUARD@@*/' => $zlibGuard,
            "'@@BUILD_ID@@'" => var_export($buildId, true),
            "'@@NAME@@'" => var_export($this->config->name, true),
            "'@@IDENTIFIER@@'" => var_export($this->config->identifier, true),
        ];

        return strtr(<<<'STUB_START'
<?php
// __FILE__ is the archive: in micro SAPI the binary itself, on macOS
// Contents/Resources/<game>.phar (which the launcher in the binary runs), and
// with a plain `php game.phar` the .phar file.
$binaryPath = __FILE__;
$binaryDir = dirname($binaryPath);
$__inAppBundle = preg_match('#\.app/Contents/(MacOS|Resources)$#', str_replace('\\', '/', $binaryDir)) === 1;
if ($__inAppBundle && str_ends_with($binaryDir, 'MacOS')) {
    $resourceBase = dirname($binaryDir) . '/Resources';
} else {
    $resourceBase = $binaryDir;
}
$__buildId = '@@BUILD_ID@@';
$__home = (string) (getenv('HOME') ?: '');

$pharBase = 'phar://' . __FILE__;

// A signed .app bundle stays untouched: logs, saves and extracted files go to
// the user's Library. Everywhere else they stay next to the executable.
if ($__inAppBundle && $__home !== '') {
    $__logDir = $__home . '/Library/Logs/' . '@@NAME@@';
    $__dataDir = $__home . '/Library/Application Support/' . '@@IDENTIFIER@@';
} else {
    $__logDir = $resourceBase;
    $__dataDir = $resourceBase;
}
// Create directories only where they are missing: mkdir() on an existing one
// fails with a warning.
$__ensureDir = static function (string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
};
$__ensureDir($__logDir);

// Engine log
$engineLogPath = $__logDir . '/game.log';
if (is_file($engineLogPath)) {
    @rename($engineLogPath, $__logDir . '/game.log.1'); // keep the previous start
}
file_put_contents($engineLogPath, '[' . date('Y-m-d H:i:s') . "] PHPolygon starting...\n");
$__engineLog = function(string $msg) use ($engineLogPath) {
    file_put_contents($engineLogPath, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
};
set_error_handler(function($severity, $message, $file, $line) use ($__engineLog) {
    if (!(error_reporting() & $severity)) {
        return false; // silenced with @: a probe that may fail, not a problem worth logging
    }
    $type = match($severity) {
        E_WARNING, E_USER_WARNING => 'WARNING',
        E_NOTICE, E_USER_NOTICE => 'NOTICE',
        E_DEPRECATED, E_USER_DEPRECATED => 'DEPRECATED',
        default => 'ERROR',
    };
    $__engineLog("{$type}: {$message} in {$file}:{$line}");
    return false;
});
set_exception_handler(function(\Throwable $e) use ($__engineLog) {
    $__engineLog("FATAL: Uncaught " . get_class($e) . ": " . $e->getMessage());
    $__engineLog("  in " . $e->getFile() . ":" . $e->getLine());
    $__engineLog("  Stack trace:\n" . $e->getTraceAsString());
});
register_shutdown_function(function() use ($__engineLog) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $__engineLog("FATAL ERROR: {$error['message']} in {$error['file']}:{$error['line']}");
    }
    $__engineLog("Engine shutdown.");
});
/*@@ZLIB_GUARD@@*/
define('DS', DIRECTORY_SEPARATOR);
define('PHPOLYGON_PATH_ROOT', $resourceBase);
define('PHPOLYGON_PATH_LOGS', $__logDir);
define('PHPOLYGON_PATH_SAVES', $__dataDir . DS . 'saves');
define('PHPOLYGON_PATH_MODS', $__dataDir . DS . 'mods');

// CLI flags: --dev enables EngineConfig::$devMode (logging + F3 overlay).
// --dev-monitor (or --dev=monitor) implies --dev and switches the F3
// overlay into the expanded hardware-monitor panel.
foreach ($_SERVER['argv'] ?? [] as $__arg) {
    if ($__arg === '--dev') {
        define('PHPOLYGON_CLI_DEV', true);
    } elseif ($__arg === '--dev-monitor' || $__arg === '--dev=monitor') {
        if (!defined('PHPOLYGON_CLI_DEV')) define('PHPOLYGON_CLI_DEV', true);
        define('PHPOLYGON_CLI_DEV_MONITOR', true);
    }
}
unset($__arg);

$__engineLog("Resource base: " . $resourceBase);
$__engineLog("PHAR base: " . $pharBase);
$__engineLog("Build: " . $__buildId);

foreach ([PHPOLYGON_PATH_SAVES, PHPOLYGON_PATH_MODS] as $__dir) {
    $__ensureDir($__dir);
}

$__engineLog("Loading autoloader...");
require $pharBase . '/vendor/autoload.php';
$__engineLog("Autoloader ready.");

// Files the archive carries for native loaders (textures, audio, fonts), which
// cannot read phar:// paths. They are copied once per build, not per start: next
// to the executable when that is writable, else - and always inside a signed
// .app, whose build already ships them pre-extracted - into the user's cache.
$__extractDirs = [];
foreach (['assets', 'resources'] as $__part) {
    $__installDir = $resourceBase . DS . $__part;
    $__targetDir = $__installDir;
    if (is_dir($pharBase . '/' . $__part)) {
        $__writable = !$__inAppBundle && (is_dir($__installDir) ? is_writable($__installDir) : is_writable($resourceBase));
        if (!$__writable && !\PHPolygon\Runtime\BundleExtractor::isCurrent($__installDir, $__buildId)) {
            $__targetDir = \PHPolygon\Runtime\BundleExtractor::userCacheDir('@@IDENTIFIER@@') . DS . $__part;
        }
        try {
            \PHPolygon\Runtime\BundleExtractor::sync(
                $pharBase . '/' . $__part,
                $__targetDir,
                $__buildId,
                $__targetDir === $__installDir ? null : $__installDir,
                $__engineLog,
            );
        } catch (\Throwable $__e) {
            $__engineLog('ERROR: extracting ' . $__part . ': ' . $__e->getMessage());
        }
    } elseif (!$__inAppBundle) {
        $__ensureDir($__installDir);
    }
    $__extractDirs[$__part] = $__targetDir;
}
define('PHPOLYGON_PATH_ASSETS', $__extractDirs['assets']);
define('PHPOLYGON_PATH_RESOURCES', $__extractDirs['resources']);
unset($__part, $__installDir, $__targetDir, $__writable, $__extractDirs, $__dir);
$__engineLog("Assets: " . PHPOLYGON_PATH_ASSETS);

// Log every class autoload to catch segfaults during class loading
spl_autoload_register(function(string $class) use ($__engineLog) {
    $__engineLog("Autoloading: $class");
}, prepend: true);

STUB_START, $placeholders)
        . $additionalRequires
        . "\n\$__engineLog('Running game...');"
        . "\n\$__engineLog('Loaded extensions: ' . implode(', ', get_loaded_extensions()));"
        . "\n\$__engineLog('Memory: ' . round(memory_get_usage(true) / 1024 / 1024, 1) . ' MB');"
        . "\n\$__engineLog('Testing vio extension...');"
        . "\nif (function_exists('vio_backends')) { \$__engineLog('vio_backends: ' . implode(', ', vio_backends())); }"
        . "\n\$__engineLog('vio extension OK');"
        . "\ntry {"
        . $runCode
        . "\n} catch (\\Throwable \$__e) {"
        . "\n    \$__engineLog('FATAL: ' . get_class(\$__e) . ': ' . \$__e->getMessage());"
        . "\n    \$__engineLog('  in ' . \$__e->getFile() . ':' . \$__e->getLine());"
        . "\n    \$__engineLog('  Stack trace: ' . \$__e->getTraceAsString());"
        . "\n}"
        . "\n\$__engineLog('Game exited.');"
        . <<<'STUB_END'

__HALT_COMPILER();
STUB_END;
    }

    /**
     * Copy directory with symlink resolution and glob-based exclude filtering.
     *
     * `$prefix` is the vendor-relative path of `$src`, so the whole-path
     * {@see VENDOR_CLEANUP} rules still match inside a linked dependency.
     *
     * @param array<string> $excludePatterns
     */
    private function copyDirectoryFiltered(string $src, string $dst, array $excludePatterns, string $prefix = ''): void
    {
        @mkdir($dst, 0755, true);
        $excludes = array_map(fn(string $p) => str_replace('**/', '', $p), $excludePatterns);

        // The keys are '/'-separated on Windows too, so explode('/') below splits
        // the segments the exclude patterns (e.g. '**/tests') match against.
        foreach (FileTree::walk($src) as $relPath => $item) {
            if (self::isVendorCleanup($prefix . $relPath)) {
                continue;
            }

            // Check if any path segment matches an exclude pattern
            $skip = false;
            $segments = explode('/', $relPath);
            foreach ($excludes as $exclude) {
                foreach ($segments as $segment) {
                    if (fnmatch($exclude, $segment)) {
                        $skip = true;
                        break 2;
                    }
                }
            }
            if ($skip) {
                continue;
            }

            $target = $dst . '/' . $relPath;
            $source = $item->getRealPath() ?: $item->getPathname();
            if (FileTree::isLinkedDirectory($item->getPathname())) {
                // A symlink/junction to a directory, which the walk does not enter:
                // a Composer path-repo dependency (e.g. the engine, which appears
                // as a junction on Windows). Without this its sources never reach
                // the PHAR (the classic "Class PHPolygon\Engine not found" at
                // runtime). Copy its real tree, skipping the dev checkout's
                // VCS/build junk and its own (already-flattened) vendor.
                $depExcludes = array_merge($excludePatterns, [
                    '**/.git', '**/.github', '**/.idea',
                    '**/node_modules', '**/vendor', '**/build', '**/dist',
                ]);
                $this->copyDirectoryFiltered($source, $target, $depExcludes, $prefix . $relPath . '/');
            } elseif ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                @mkdir(dirname($target), 0755, true);
                copy($source, $target);
            }
        }
    }

    /** Whether a vendor-relative path, or one of its parent directories, is a {@see VENDOR_CLEANUP} path. */
    private static function isVendorCleanup(string $vendorPath): bool
    {
        // A package's own license (vendor/<vendor>/<package>/LICENSE.md) always ships.
        if (substr_count($vendorPath, '/') === 2 && self::isLicenseFile(basename($vendorPath))) {
            return false;
        }
        $path = '';
        foreach (explode('/', $vendorPath) as $segment) {
            $path .= ($path === '' ? '' : '/') . $segment;
            foreach (self::VENDOR_CLEANUP as $pattern) {
                if (fnmatch($pattern, $path, FNM_PATHNAME)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** LICENSE, LICENSE.md, COPYING, NOTICE.txt, ... */
    private static function isLicenseFile(string $name): bool
    {
        return preg_match('/^(licen[cs]e|copying|notice)(\.|$)/i', $name) === 1;
    }
}
