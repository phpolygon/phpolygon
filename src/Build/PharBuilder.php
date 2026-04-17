<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use Phar;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PharBuilder
{
    private BuildConfig $config;

    public function __construct(BuildConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Create staging directory with resolved symlinks and filtered content
     */
    public function stage(string $stagingDir): void
    {
        if (is_dir($stagingDir)) {
            $this->removeDirectory($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        $projectRoot = $this->config->projectRoot;

        // Stage vendor/ with symlink resolution and exclude filtering
        $vendorSrc = $projectRoot . '/vendor';
        $vendorDst = $stagingDir . '/vendor';
        if (is_dir($vendorSrc)) {
            $this->copyDirectoryFiltered($vendorSrc, $vendorDst, $this->config->pharExclude);
        }

        // Stage src/
        $srcDir = $projectRoot . '/src';
        if (is_dir($srcDir)) {
            $this->copyDirectory($srcDir, $stagingDir . '/src');
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

        // Stage resource directories (exclude external ones)
        $resourcesDir = $projectRoot . '/resources';
        if (is_dir($resourcesDir)) {
            $external = array_map(function ($path) {
                return basename($path);
            }, $this->config->externalResources);

            $entries = scandir($resourcesDir);
            if ($entries !== false) {
                foreach ($entries as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $itemPath = $resourcesDir . '/' . $item;
                    if (is_dir($itemPath) && !in_array($item, $external) && !in_array('resources/' . $item, $this->config->externalResources)) {
                        $this->copyDirectory($itemPath, $stagingDir . '/resources/' . $item);
                    } elseif (is_file($itemPath)) {
                        @mkdir($stagingDir . '/resources', 0755, true);
                        copy($itemPath, $stagingDir . '/resources/' . $item);
                    }
                }
            }
        }

        // Stage mesh cache if present
        $meshCacheDir = $projectRoot . '/.phpolygon/mesh-cache';
        if (is_dir($meshCacheDir)) {
            $this->copyDirectory($meshCacheDir, $stagingDir . '/resources/meshes');
        }

        // Stage assets/ directory
        $assetsDir = $projectRoot . '/assets';
        if (is_dir($assetsDir)) {
            $this->copyDirectory($assetsDir, $stagingDir . '/assets');
        }
    }

    /**
     * Create PHAR from staged directory
     */
    public function build(string $stagingDir, string $pharPath): void
    {
        if (file_exists($pharPath)) {
            unlink($pharPath);
        }

        $phar = new Phar($pharPath, 0, basename($pharPath));
        $phar->startBuffering();
        $phar->buildFromDirectory($stagingDir);
        $phar->setStub($this->generateStub());
        $phar->stopBuffering();
    }

    /**
     * Generate the PHAR stub.
     * Handles micro SAPI detection, macOS .app bundles, PHPolygon path setup,
     * resource extraction, and engine bootstrap.
     */
    public function generateStub(): string
    {
        $additionalRequires = '';
        foreach ($this->config->additionalRequires as $require) {
            $additionalRequires .= "\nrequire_once \$pharBase . '/{$require}';";
        }

        $runCode = '';
        if ($this->config->run !== '') {
            $runCode = "\n" . $this->config->run;
        }

        return <<<'STUB_START'
<?php
// In micro SAPI, PHP_BINARY is empty but __FILE__ points to the binary
$binaryPath = PHP_BINARY ?: __FILE__;
$binaryDir = dirname($binaryPath);
if (str_contains($binaryDir, '.app/Contents/MacOS')) {
    $resourceBase = dirname($binaryDir) . '/Resources';
} else {
    $resourceBase = $binaryDir;
}

$pharBase = 'phar://' . __FILE__;

// Engine log
$engineLogPath = $resourceBase . '/game.log';
file_put_contents($engineLogPath, '[' . date('Y-m-d H:i:s') . "] PHPolygon starting...\n");
$__engineLog = function(string $msg) use ($engineLogPath) {
    file_put_contents($engineLogPath, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
};
set_error_handler(function($severity, $message, $file, $line) use ($__engineLog) {
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

define('DS', DIRECTORY_SEPARATOR);
define('PHPOLYGON_PATH_ROOT', $resourceBase);
define('PHPOLYGON_PATH_ASSETS', $resourceBase . DS . 'assets');
define('PHPOLYGON_PATH_RESOURCES', $resourceBase . DS . 'resources');
define('PHPOLYGON_PATH_SAVES', $resourceBase . DS . 'saves');
define('PHPOLYGON_PATH_MODS', $resourceBase . DS . 'mods');

$__engineLog("Resource base: " . $resourceBase);
$__engineLog("PHAR base: " . $pharBase);

@mkdir(PHPOLYGON_PATH_ASSETS, 0755, true);
@mkdir(PHPOLYGON_PATH_RESOURCES, 0755, true);
@mkdir(PHPOLYGON_PATH_SAVES, 0755, true);
@mkdir(PHPOLYGON_PATH_MODS, 0755, true);

// Extract assets from PHAR (always overwrite to ensure updates)
$pharAssets = $pharBase . '/assets';
if (is_dir($pharAssets)) {
    $assetIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pharAssets, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $pharAssetsLen = strlen($pharAssets);
    foreach ($assetIterator as $assetItem) {
        $relPath = substr($assetItem->getPathname(), $pharAssetsLen + 1);
        $targetPath = PHPOLYGON_PATH_ASSETS . DS . $relPath;
        if ($assetItem->isDir()) {
            @mkdir($targetPath, 0755, true);
        } else {
            @mkdir(dirname($targetPath), 0755, true);
            copy($assetItem->getPathname(), $targetPath);
        }
    }
}

// Extract resources from PHAR
$pharResources = $pharBase . '/resources';
if (is_dir($pharResources)) {
    $resIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pharResources, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $pharResLen = strlen($pharResources);
    foreach ($resIterator as $resItem) {
        $relPath = substr($resItem->getPathname(), $pharResLen + 1);
        $targetPath = PHPOLYGON_PATH_RESOURCES . DS . $relPath;
        if ($resItem->isDir()) {
            @mkdir($targetPath, 0755, true);
        } else {
            @mkdir(dirname($targetPath), 0755, true);
            copy($resItem->getPathname(), $targetPath);
        }
    }
}

$__engineLog("Loading autoloader...");
require $pharBase . '/vendor/autoload.php';
$__engineLog("Autoloader ready.");

STUB_START
        . $additionalRequires
        . "\n\$__engineLog('Running game...');"
        . $runCode . <<<'STUB_END'

__HALT_COMPILER();
STUB_END;
    }

    /**
     * Copy directory with symlink resolution and glob-based exclude filtering.
     *
     * @param array<string> $excludePatterns
     */
    private function copyDirectoryFiltered(string $src, string $dst, array $excludePatterns): void
    {
        @mkdir($dst, 0755, true);
        $excludes = array_map(fn(string $p) => str_replace('**/', '', $p), $excludePatterns);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $srcLen = strlen($src);
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relPath = substr($item->getPathname(), $srcLen + 1);

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
            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                @mkdir(dirname($target), 0755, true);
                copy($item->getRealPath() ?: $item->getPathname(), $target);
            }
        }
    }

    private function copyDirectory(string $src, string $dst): void
    {
        @mkdir($dst, 0755, true);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                @mkdir(dirname($target), 0755, true);
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
