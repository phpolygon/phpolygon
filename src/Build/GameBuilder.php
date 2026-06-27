<?php

declare(strict_types=1);

namespace PHPolygon\Build;

class GameBuilder
{
    private BuildConfig $config;
    private PharBuilder $pharBuilder;
    private StaticPhpResolver $staticPhpResolver;
    private PlatformPackager $platformPackager;
    private IosAppBuilder $iosAppBuilder;

    /** @var callable|null */
    private $logger = null;

    public function __construct(BuildConfig $config)
    {
        $this->config = $config;
        $this->pharBuilder = new PharBuilder($config);
        $this->staticPhpResolver = new StaticPhpResolver();
        $this->platformPackager = new PlatformPackager($config);
        $this->iosAppBuilder = new IosAppBuilder($config);
    }

    /**
     * @param callable(string, string): void $logger  fn(level, message)
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
        $this->staticPhpResolver->setLogger(fn(string $msg) => $logger('info', $msg));
        $this->iosAppBuilder->setLogger(fn(string $msg) => $logger('info', $msg));
    }

    /**
     * Run the full build pipeline.
     *
     * @param ?string $sharedPharPath Optional cross-target PHAR cache path. The
     *   staged PHP code is identical for every desktop target of the same
     *   variant/build-type, so when this points at an existing file, vendor
     *   prep + staging + PHAR creation are skipped and the PHAR is reused (only
     *   micro.sfx + combine + package run per target). When it points at a path
     *   that does not exist yet, the PHAR is built once and persisted there so
     *   sibling targets reuse it. iOS never reuses (it links the staged tree).
     * @return array{outputPath: string, pharSize: int, binarySize: int, bundleSize: int}
     */
    public function build(string $platform, string $outputDir, ?string $microSfxPath = null, ?string $arch = null, string $variant = 'base', string $buildType = 'full', string $phpVersion = '8.5', bool $iosRelease = false, bool $iosExportIpa = false, ?string $sharedPharPath = null): array
    {
        $arch = $arch ?? StaticPhpResolver::detectArch();

        // Auto-select ZTS variant when threading is enabled
        if ($variant === 'base' && $this->config->enableThreading) {
            $variant = $this->config->getPhpVariant();
        }

        $suffix = $variant !== 'base' ? "-{$variant}" : '';
        if ($buildType !== 'full') {
            $suffix .= "-{$buildType}";
        }
        $platformOutputDir = $outputDir . '/' . $platform . '-' . $arch . $suffix;

        // Clean previous build output
        if (is_dir($platformOutputDir)) {
            $this->log('info', 'Cleaning previous build...');
            $this->removeDirectory($platformOutputDir);
        }
        mkdir($platformOutputDir, 0755, true);

        $tempDir = sys_get_temp_dir() . '/phpolygon-build-' . $this->config->name . '-' . getmypid();

        // Reuse a cross-target PHAR when one is already built (see $sharedPharPath
        // docblock). iOS never reuses - it links the staged tree, it has no PHAR.
        $isIos = str_starts_with($platform, 'ios');
        $reusePhar = !$isIos
            && $sharedPharPath !== null
            && is_file($sharedPharPath)
            && (int) filesize($sharedPharPath) > 0;
        // Only the path that prepares vendor must restore it afterwards.
        $vendorPrepared = false;

        try {
            if ($reusePhar) {
                /** @var string $sharedPharPath */
                $pharPath = $sharedPharPath;
                $pharSize = (int) filesize($pharPath);
                $this->log('info', sprintf('Reusing prebuilt PHAR (%.2f MB)...', $pharSize / 1024 / 1024));
                // The combine step writes into $tempDir, which is normally created
                // as a side effect of staging. Staging is skipped on reuse, so
                // create the temp dir explicitly.
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
            } else {
                // Phase 1: Prepare vendor (install --no-dev)
                $this->log('info', 'Installing production dependencies...');
                $this->prepareVendor();
                $vendorPrepared = true;

                // Phase 2: Stage sources
                $stagingDir = $tempDir . '/staging';
                $this->log('info', 'Staging sources...');
                $this->pharBuilder->stage($stagingDir);
                $fileCount = $this->countFiles($stagingDir);
                $this->log('success', "Staged {$fileCount} files");

                // Phase 2b: Apply build type constant overrides
                $buildTypeConfig = $this->config->buildTypes[$buildType] ?? [];
                if ($buildType !== 'full' && isset($buildTypeConfig['constants']) && is_array($buildTypeConfig['constants'])) {
                    /** @var array<string, mixed> $constants */
                    $constants = $buildTypeConfig['constants'];
                    $this->applyBuildTypeConstants($stagingDir, $constants);
                    $this->log('info', "Applied build type '{$buildType}' constants");
                }

                // iOS branch: no phar / micro.sfx / combine. Link the staged tree
                // against an embed libphp.a in a UIKit/Metal wrapper via Xcode.
                if ($isIos) {
                    $libphpDir = $this->resolveIosBuildroot($platform);
                    $mode = $iosRelease ? 'release' : 'debug';
                    $this->log('info', "Building iOS {$mode} ({$platform}) against {$libphpDir}...");
                    $appPath = $this->iosAppBuilder->build($stagingDir, $libphpDir, $platformOutputDir, $platform, $mode, $iosExportIpa);
                    $this->log('success', 'Output: ' . $appPath);
                    // tempDir + vendor are cleaned up by the finally block below.
                    return [
                        'outputPath' => $appPath,
                        'pharSize'   => 0,
                        'binarySize' => 0,
                        'bundleSize' => $this->getDirectorySize($appPath),
                    ];
                }

                // Phase 3: Create PHAR
                $pharPath = $tempDir . '/' . strtolower($this->config->name) . '.phar';
                $this->log('info', 'Creating PHAR archive...');
                $this->pharBuilder->build($stagingDir, $pharPath);
                $pharSize = (int) filesize($pharPath);
                $this->log('success', sprintf('PHAR created: %.2f MB', $pharSize / 1024 / 1024));

                // Persist the freshly built PHAR so sibling targets can reuse it.
                if ($sharedPharPath !== null) {
                    $sharedDir = dirname($sharedPharPath);
                    if (!is_dir($sharedDir)) {
                        mkdir($sharedDir, 0755, true);
                    }
                    copy($pharPath, $sharedPharPath);
                }
            }

            // Phase 4: Resolve static PHP binary
            $this->log('info', 'Resolving micro.sfx binary...');
            $sfxPath = $this->staticPhpResolver->resolve($microSfxPath, $platform, $arch, $variant, $phpVersion);
            $this->log('success', 'Found micro.sfx: ' . $sfxPath);

            // Phase 4b: Resolve platform-specific runtime libs (vulkan-1.dll on Windows)
            $runtimeLibs = $this->staticPhpResolver->resolveRuntimeLibs($platform, $arch, $variant, $phpVersion);
            foreach ($runtimeLibs as $lib) {
                $this->log('success', 'Found runtime lib: ' . $lib);
            }

            // Phase 5: Combine executable
            $combinedPath = $tempDir . '/' . $this->config->name;
            $this->log('info', 'Combining executable...');
            $this->combineExecutable($sfxPath, $pharPath, $combinedPath);
            $binarySize = filesize($combinedPath);
            $this->log('success', sprintf('Binary: %.2f MB', $binarySize / 1024 / 1024));

            // Phase 6: Package for platform
            $this->log('info', "Packaging for {$platform}...");
            $outputPath = $this->platformPackager->package($combinedPath, $platformOutputDir, $platform, $variant, $runtimeLibs);
            $this->log('success', 'Output: ' . $outputPath);

            // Phase 7: Report
            $bundleSize = $this->getDirectorySize($outputPath);

            return [
                'outputPath' => $outputPath,
                'pharSize' => (int) $pharSize,
                'binarySize' => (int) $binarySize,
                'bundleSize' => $bundleSize,
            ];
        } finally {
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
            // Only restore dev dependencies if this call actually prepared vendor.
            if ($vendorPrepared) {
                $this->restoreVendor();
            }
        }
    }

    /**
     * Locate the static-php-cli buildroot holding the iOS embed libphp.a and
     * PHP headers. Resolution order:
     *   1. PHPOLYGON_IOS_BUILDROOT env
     *   2. build.json platforms.ios.buildroot
     *   3. ../static-php-cli/buildroot relative to the project root
     */
    private function resolveIosBuildroot(string $slice): string
    {
        $candidates = [];
        $env = getenv('PHPOLYGON_IOS_BUILDROOT');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        $ios = $this->config->platforms['ios'] ?? [];
        if (isset($ios['buildroot']) && is_string($ios['buildroot'])) {
            $candidates[] = $ios['buildroot'];
        }
        $candidates[] = dirname($this->config->projectRoot) . '/static-php-cli/buildroot';

        foreach ($candidates as $dir) {
            if (is_file($dir . '/lib/libphp.a')) {
                return $dir;
            }
        }
        throw new \RuntimeException(
            "iOS buildroot with lib/libphp.a not found. Looked in:\n  - " .
            implode("\n  - ", $candidates) . "\n" .
            "Set PHPOLYGON_IOS_BUILDROOT or platforms.ios.buildroot in build.json, " .
            "and build libphp.a with: SPC_TARGET={$slice} bin/spc build <exts> --build-embed"
        );
    }

    private function prepareVendor(): void
    {
        $cmd = sprintf(
            'cd %s && composer update --no-dev --no-interaction --ignore-platform-reqs 2>&1',
            escapeshellarg($this->config->projectRoot)
        );
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException("composer update --no-dev failed:\n" . implode("\n", $output));
        }
    }

    private function restoreVendor(): void
    {
        $cmd = sprintf(
            'cd %s && composer update --no-interaction --ignore-platform-reqs 2>&1',
            escapeshellarg($this->config->projectRoot)
        );
        exec($cmd);
    }

    private function combineExecutable(string $sfxPath, string $pharPath, string $outputPath): void
    {
        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Failed to open output file: {$outputPath}");
        }
        try {
            foreach ([$sfxPath, $pharPath] as $inputFile) {
                $in = fopen($inputFile, 'rb');
                if ($in === false) {
                    throw new \RuntimeException("Failed to open input file: {$inputFile}");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        chmod($outputPath, 0755);
    }

    private function countFiles(string $dir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $_) {
            $count++;
        }
        return $count;
    }

    private function getDirectorySize(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $constants
     */
    private function applyBuildTypeConstants(string $stagingDir, array $constants): void
    {
        $file = $stagingDir . '/bootstrap.php';
        if (!file_exists($file)) {
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }
        foreach ($constants as $name => $value) {
            $phpValue = var_export($value, true);
            $pattern = "/(define\(\s*['\"]" . preg_quote($name, '/') . "['\"]\s*,\s*).+?(\))/";
            $replacement = '${1}' . $phpValue . '${2}';
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }
        file_put_contents($file, $content);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            ($this->logger)($level, $message);
        }
    }
}
