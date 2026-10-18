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
    private BuildHookRunner $hookRunner;
    private NativeUpscalerResolver $upscalerResolver;
    private ProductionVendor $productionVendor;

    /** @var callable|null */
    private $logger = null;

    /**
     * @param ?string $composerCommand shell command prefix that runs Composer for
     *   the production install (see {@see ProductionVendor}); null = PHPOLYGON_COMPOSER or `composer`
     */
    public function __construct(BuildConfig $config, ?string $composerCommand = null)
    {
        $this->config = $config;
        $this->productionVendor = new ProductionVendor($config->projectRoot, $composerCommand);
        $this->pharBuilder = new PharBuilder($config);
        $this->staticPhpResolver = new StaticPhpResolver();
        $this->platformPackager = new PlatformPackager($config);
        $this->iosAppBuilder = new IosAppBuilder($config);
        $this->upscalerResolver = new NativeUpscalerResolver($config->projectRoot, $this->staticPhpResolver->getCacheDir());
        $this->hookRunner = new BuildHookRunner(
            $config,
            fn (string $platform, string $arch, string $variant, string $phpVersion): string
                => $this->staticPhpResolver->resolve(null, $platform, $arch, $variant, $phpVersion),
            runtimeLibsResolver: fn (string $platform, string $arch, string $variant, string $phpVersion): array
                => $this->staticPhpResolver->resolveRuntimeLibs($platform, $arch, $variant, $phpVersion),
        );
    }

    /**
     * @param callable(string, string): void $logger  fn(level, message)
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
        $this->staticPhpResolver->setLogger(fn(string $msg) => $logger('info', $msg));
        $this->iosAppBuilder->setLogger(fn(string $msg) => $logger('info', $msg));
        $this->hookRunner->setLogger(fn(string $level, string $msg) => $logger($level, $msg));
        $this->upscalerResolver->setLogger(fn(string $level, string $msg) => $logger($level, $msg));
        $this->productionVendor->setLogger(fn(string $level, string $msg) => $logger($level, $msg));
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
     * @param ?string $derivePharFrom a PHAR already built for another build type of
     *   the same variant: when $sharedPharPath does not exist yet, it is made from this
     *   one by rewriting the type's constants ({@see PharBuilder::deriveBuildType()}) -
     *   no hooks, no staging. A type with build.json `"sharedPhar": false` is built
     *   from scratch (its hooks may produce type-specific files).
     * @return array{outputPath: string, pharSize: int, binarySize: int, bundleSize: int}
     */
    public function build(string $platform, string $outputDir, ?string $microSfxPath = null, ?string $arch = null, string $variant = 'base', string $buildType = 'full', string $phpVersion = '8.5', bool $iosRelease = false, bool $iosExportIpa = false, ?string $sharedPharPath = null, ?string $derivePharFrom = null): array
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
            FileTree::remove($platformOutputDir);
        }
        mkdir($platformOutputDir, 0755, true);

        // One temp dir per build call: parallel builds (also from one process, or
        // from containers whose PIDs collide on a shared temp dir) never share it.
        $tempDir = sys_get_temp_dir() . '/phpolygon-build-' . $this->config->name . '-' . getmypid() . '-' . bin2hex(random_bytes(4));

        // Reuse a cross-target PHAR when one is already built (see $sharedPharPath
        // docblock). iOS never reuses - it links the staged tree, it has no PHAR.
        $isIos = str_starts_with($platform, 'ios');
        if (!$isIos && $sharedPharPath !== null && !is_file($sharedPharPath)
            && $derivePharFrom !== null && is_file($derivePharFrom)
            && ($this->config->buildTypes[$buildType]['sharedPhar'] ?? true) !== false
        ) {
            $this->log('info', "Deriving the '{$buildType}' PHAR from " . basename($derivePharFrom) . ' (no hooks, no staging)...');
            if (!is_dir(dirname($sharedPharPath))) {
                mkdir(dirname($sharedPharPath), 0755, true);
            }
            $this->pharBuilder->deriveBuildType(
                $derivePharFrom,
                $sharedPharPath,
                (new BuildVerifier($this->config))->typeConstants($buildType),
            );
        }
        $reusePhar = !$isIos
            && $sharedPharPath !== null
            && is_file($sharedPharPath)
            && (int) filesize($sharedPharPath) > 0;

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
                // Phase 0: build.json beforeBuild hooks (asset pre-bakes and the like),
                // in the project's own (development) install.
                $this->hookRunner->runBeforeBuild([
                    'platform' => $platform,
                    'arch' => $arch,
                    'variant' => $variant,
                    'type' => $buildType,
                    'phpVersion' => $phpVersion,
                ]);

                // Phase 1: Production dependencies (install --no-dev from the
                // project's lock) into a working copy under the temp dir. The
                // project's vendor/ and composer.lock are never touched.
                $this->log('info', 'Installing production dependencies...');
                $vendorDir = $this->productionVendor->install($tempDir . '/composer');

                // Phase 2: Stage sources
                $stagingDir = $tempDir . '/staging';
                $this->log('info', 'Staging sources...');
                $this->pharBuilder->stage($stagingDir, $vendorDir);
                $fileCount = $this->countFiles($stagingDir);
                $this->log('success', "Staged {$fileCount} files");

                // Phase 2b: Apply build type constant overrides in every staged
                // bootstrap file that defines them (bootstrap.php, phar.additionalRequires);
                // a constant none of them defines fails the build.
                $buildTypeConfig = $this->config->buildTypes[$buildType] ?? [];
                if ($buildType !== 'full' && isset($buildTypeConfig['constants']) && is_array($buildTypeConfig['constants'])) {
                    /** @var array<string, mixed> $constants */
                    $constants = $buildTypeConfig['constants'];
                    $applied = BuildConstants::apply($stagingDir, $constants, BuildConstants::files($this->config, $stagingDir));
                    foreach ($applied as $name => $files) {
                        $this->log('info', "Build type '{$buildType}': {$name} = " . var_export($constants[$name], true) . ' in ' . implode(', ', $files));
                    }
                }

                // Phase 2c: Transpile UI layouts to PHP so the PHAR ships
                // zero-parse builder classes regardless of what is committed.
                if ($this->config->uiTranspile !== null) {
                    $ui = $this->config->uiTranspile;
                    $written = (new \PHPolygon\UI\Widget\UiLayoutTranspiler())->transpileDir(
                        $stagingDir . '/' . $ui['dir'],
                        $stagingDir . '/' . $ui['output'],
                        $ui['namespace'],
                    );
                    $this->log('info', 'Transpiled ' . count($written) . ' UI layout(s) to PHP');
                }

                // Phase 2d: build.json assets.meshes = binary - the staged
                // *.mesh.json become compact *.mesh.bin; the sources stay in the project.
                if ($this->config->meshAssets === BuildConfig::MESH_ASSETS_BINARY) {
                    $stats = \PHPolygon\Geometry\MeshAssetLoader::compileDirectory(
                        $stagingDir . '/assets',
                        $this->config->meshZlibLevel,
                        removeSources: true,
                    );
                    $this->log('info', sprintf(
                        'Compiled %d mesh asset(s): %.1f MB JSON -> %.1f MB binary',
                        $stats['files'],
                        $stats['sourceBytes'] / 1024 / 1024,
                        $stats['compiledBytes'] / 1024 / 1024,
                    ));
                    // resources.exclude also holds for what the compile produced.
                    $pruned = $this->pharBuilder->pruneExcluded($stagingDir);
                    if ($pruned > 0) {
                        $this->log('info', "Dropped {$pruned} compiled file(s) matching resources.exclude");
                    }
                }

                // iOS branch: no phar / micro.sfx / combine. Link the staged tree
                // against an embed libphp.a in a UIKit/Metal wrapper via Xcode.
                if ($isIos) {
                    $libphpDir = $this->resolveIosBuildroot($platform);
                    $mode = $iosRelease ? 'release' : 'debug';
                    $this->log('info', "Building iOS {$mode} ({$platform}) against {$libphpDir}...");
                    $appPath = $this->iosAppBuilder->build($stagingDir, $libphpDir, $platformOutputDir, $platform, $mode, $iosExportIpa);
                    $this->log('success', 'Output: ' . $appPath);
                    // tempDir (staging + production vendor) is removed by the finally block below.
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

            // Phase 4c: build.json upscalers - FSR 3 / DLSS runtimes next to the
            // binary, their notices, the DLSS project id for the embedded ini.
            $upscalers = $this->config->upscalers->isEmpty()
                ? new NativeUpscalerBundle()
                : $this->upscalerResolver->resolve($this->config->upscalers, $platform);
            array_push($runtimeLibs, ...$upscalers->files);

            // Phase 5: Combine executable. macOS carries only a launcher; the PHAR
            // ships once in Contents/Resources, shared by the arm64 and x86_64
            // binaries of a universal bundle.
            $combinedPath = $tempDir . '/' . $this->config->name;
            $payloadPath = $pharPath;
            $separatePhar = null;
            if ($platform === 'macos') {
                $payloadPath = $tempDir . '/launcher.php';
                file_put_contents($payloadPath, $this->pharBuilder->generateMacLauncher($this->platformPackager->macPharFile()));
                $separatePhar = $pharPath;
            }
            $this->log('info', 'Combining executable...');
            $this->combineExecutable($sfxPath, $payloadPath, $combinedPath, [...$this->config->phpIni, ...$upscalers->ini]);
            $binarySize = filesize($combinedPath);
            $this->log('success', sprintf('Binary: %.2f MB', $binarySize / 1024 / 1024));

            // Phase 6: Package for platform
            $this->log('info', "Packaging for {$platform}...");
            $outputPath = $this->platformPackager->package(
                $combinedPath,
                $platformOutputDir,
                $platform,
                $variant,
                $runtimeLibs,
                $upscalers->notices($platform === 'windows' ? "\r\n" : "\n"),
                $separatePhar,
            );
            $this->log('success', 'Output: ' . $outputPath);

            // Phase 6b: build.json afterPackage hooks, per target (also on --phar reuse).
            $this->hookRunner->runAfterPackage(
                ['platform' => $platform, 'arch' => $arch, 'variant' => $variant, 'type' => $buildType, 'phpVersion' => $phpVersion],
                $outputPath,
                $platform === 'macos' ? $outputPath . '/Contents/Resources' : $outputPath,
            );

            // Phase 6c: verify the artifact against build.json - the PHAR that went
            // into this target (also a reused one) and the packaged files.
            $this->verify($pharPath, $buildType, $platform === 'macos' ? $outputPath . '/Contents/Resources' : $outputPath);

            // Phase 7: Report
            $bundleSize = $this->getDirectorySize($outputPath);

            return [
                'outputPath' => $outputPath,
                'pharSize' => (int) $pharSize,
                'binarySize' => (int) $binarySize,
                'bundleSize' => $bundleSize,
            ];
        } finally {
            // A leftover temp dir must not replace the build's own result or exception.
            try {
                FileTree::remove($tempDir);
            } catch (\RuntimeException $e) {
                $this->log('warning', $e->getMessage());
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

    /**
     * @param array<string, string> $ini settings embedded between runtime and PHAR
     */
    private function combineExecutable(string $sfxPath, string $pharPath, string $outputPath, array $ini): void
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
                // build.json php.ini goes between the runtime and the PHAR.
                if ($inputFile === $sfxPath && $ini !== []) {
                    fwrite($out, MicroIni::block($ini));
                    $embedded = MicroIni::settings($ini);
                    $this->log('info', 'Embedded php.ini: ' . implode(', ', array_map(
                        static fn (string $key, string $value): string => "{$key}={$value}",
                        array_keys($embedded),
                        $embedded,
                    )));
                }
            }
        } finally {
            fclose($out);
        }
        chmod($outputPath, 0755);
    }

    private function countFiles(string $dir): int
    {
        $count = 0;
        foreach (FileTree::walk($dir) as $item) {
            $count += (int) !$item->isDir();
        }
        return $count;
    }

    private function getDirectorySize(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        $size = 0;
        foreach (FileTree::walk($path) as $item) {
            if (!$item->isDir()) {
                $size += (int) $item->getSize();
            }
        }
        return $size;
    }

    /**
     * {@see BuildVerifier} on the built PHAR; a failure fails the build.
     * PHPOLYGON_SKIP_VERIFY=1 turns it into a warning.
     */
    private function verify(string $pharPath, string $buildType, string $resourcesRoot): void
    {
        $result = (new BuildVerifier($this->config))->verifyPhar($pharPath, $buildType, $resourcesRoot);
        if ($result->ok()) {
            if ($result->passed !== []) {
                $this->log('success', "Verified ({$buildType}): " . implode(', ', $result->passed));
            }
            return;
        }
        $message = "Build verification failed ({$buildType}):\n  - " . implode("\n  - ", $result->problems);
        if (getenv('PHPOLYGON_SKIP_VERIFY') === '1') {
            $this->log('warning', $message . "\n  (PHPOLYGON_SKIP_VERIFY=1 - continuing)");
            return;
        }
        throw new \RuntimeException($message);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            ($this->logger)($level, $message);
        }
    }
}
