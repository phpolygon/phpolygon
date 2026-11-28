<?php

declare(strict_types=1);

namespace PHPolygon\Build;

class StaticPhpResolver
{
    private const GITHUB_REPO = 'hmennen90/static-php-cli';

    /** Release feed of the DirectX Shader Compiler (MIT/LLVM licensed, dxil.dll redistributable). */
    private const string DXC_RELEASE_URL = 'https://api.github.com/repos/microsoft/DirectXShaderCompiler/releases/latest';

    private string $cacheDir;

    private string $repository;

    /** @var callable|null */
    private $logger = null;

    /**
     * @param ?string $cacheDir null = `$PHPOLYGON_HOME/build-cache`, else `~/.phpolygon/build-cache`
     * @param ?string $repository releases repository of the runtimes; null = `$SPC_REPO`, else the default fork
     */
    public function __construct(
        ?string $cacheDir = null,
        private readonly ReleaseAssetCache $assets = new ReleaseAssetCache(),
        ?string $repository = null,
        private readonly RuntimeProbe $probe = new RuntimeProbe(),
    ) {
        $this->cacheDir = $cacheDir ?? self::defaultCacheDir();
        $this->repository = $repository ?? (getenv('SPC_REPO') ?: self::GITHUB_REPO);
    }

    /** The build cache (`~/.phpolygon/build-cache`) runtimes are kept in. */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * @param callable(string): void $logger
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Resolve a micro.sfx binary path.
     * Priority: explicit path > the asset the runtime release publishes now
     * (from the cache when its identity record matches, downloaded otherwise).
     * The cache alone is only used offline, on request ({@see ReleaseAssetCache}).
     */
    public function resolve(?string $explicitPath, string $platform, string $arch, string $variant = 'base', string $phpVersion = '8.5'): string
    {
        // 1. Explicit path from CLI
        if ($explicitPath !== null) {
            if (!file_exists($explicitPath)) {
                throw new \RuntimeException("micro.sfx not found at: {$explicitPath}");
            }
            $this->log("Runtime micro.sfx: using the given file, not checked against the release: {$explicitPath}");
            return $explicitPath;
        }

        // 2. The published runtime
        $cacheKey = self::cacheKey($platform, $arch, $variant, $phpVersion);
        $prefix = "micro-sfx-{$variant}-";
        $suffix = '-' . self::releaseOsName($platform, $arch) . '.zip';
        $path = $this->releaseFile(
            "{$cacheKey}/micro.sfx",
            $phpVersion,
            static fn (string $name): bool => str_starts_with($name, $prefix) && str_ends_with($name, $suffix),
            ['micro.sfx', 'micro.sfx.exe', 'buildroot/bin/micro.sfx', 'buildroot/bin/micro.sfx.exe'],
            $this->cacheDir . "/{$cacheKey}/micro.sfx",
        );
        if ($path !== null) {
            chmod($path, 0755);
            return $path;
        }

        throw new \RuntimeException(
            "No micro.sfx binary found for {$variant}/{$platform}-{$arch}: release runtime-php{$phpVersion} of " .
            "{$this->repository} publishes none, or (offline) none is cached with an identity record.\n\n" .
            "Options:\n" .
            "  1. Provide one with --micro-sfx <path>\n" .
            "  2. Trigger the 'Build Game micro.sfx' workflow in " . $this->repository . "\n" .
            "  3. Try a different PHP version with --php-version 8.4 or 8.5"
        );
    }

    /**
     * Resolve platform-specific runtime support libraries that must be shipped
     * alongside the final binary.
     *
     * Windows only:
     *   - vulkan-1.dll: php-vio links against vulkan-loader as a dynamic
     *     library, so the DLL has to sit next to the executable.
     *   - d3dcompiler_47.dll: the d3d11/d3d12 vio backends compile HLSL at
     *     runtime via D3DCompile and hard-link it as a load-time import; a
     *     clean Windows install lacks it, so the exe fails to start with
     *     0xC0000135 unless it ships next to the binary.
     *
     * All four desktop platforms:
     *   - the Steam API library (steam_api64.dll / libsteam_api.so /
     *     libsteam_api.dylib): a game using php-steamworks hard-imports it, so
     *     it must ship next to the binary / inside the .app. The asset name has
     *     no variant suffix (libsteam-api-<ver>-<os>.zip).
     *
     * A library the release does not publish is left out with a log line. One it
     * publishes but that cannot be verified or downloaded fails the build.
     *
     * @return list<string> Absolute paths to runtime libs, empty if none needed
     */
    public function resolveRuntimeLibs(string $platform, string $arch, string $variant = 'base', string $phpVersion = '8.5'): array
    {
        $cacheKey = self::cacheKey($platform, $arch, $variant, $phpVersion);
        $osName = self::releaseOsName($platform, $arch);

        $libs = [];

        if ($platform === 'windows') {
            foreach ([
                // Optional: without it the Vulkan backend is unavailable.
                ['vulkan-1.dll', "vulkan-1-dll-{$variant}-{$phpVersion}-", 'the Vulkan backend will be unavailable in the shipped binary.'],
                // Required by the d3d11/d3d12 backends.
                ['d3dcompiler_47.dll', "d3dcompiler-47-dll-{$variant}-{$phpVersion}-", 'the d3d11/d3d12 backends will fail to start (0xC0000135) on a clean Windows install.'],
            ] as [$file, $prefix, $consequence]) {
                $suffix = "-{$osName}.zip";
                $path = $this->releaseFile(
                    "{$cacheKey}/{$file}",
                    $phpVersion,
                    static fn (string $name): bool => str_starts_with($name, $prefix) && str_ends_with($name, $suffix),
                    [$file, "buildroot/bin/{$file}"],
                    $this->cacheDir . "/{$cacheKey}/{$file}",
                );
                if ($path !== null) {
                    $libs[] = $path;
                } else {
                    $this->log("No {$file} found for {$cacheKey} — {$consequence}");
                }
            }

            // dxcompiler.dll + dxil.dll: Shader Model 6 on D3D12 (php-vio >= 2.16,
            // EngineConfig::$vioShaderModel = 6). Optional - php-vio falls back to
            // FXC without them - but DXC compiles a large shader set about ten
            // times faster on a cold start, so ship them whenever they resolve.
            $dxc = $this->resolveDxcDlls($cacheKey, $arch);
            if ($dxc === []) {
                $this->log("No DXC (dxcompiler.dll / dxil.dll) for {$cacheKey} — Shader Model 6 requests fall back to FXC in the shipped binary.");
            }
            array_push($libs, ...$dxc);
        }

        // Steam API library — all four desktop platforms. The cached file is
        // stored under its final, per-OS basename so PlatformPackager (which
        // copies runtime libs by basename) lands the correct name next to the
        // binary / into the .app.
        $steamLibName = $this->steamLibName($platform);
        // No variant suffix: libsteam-api-<phpVersion>-<os>.zip
        $assetName = "libsteam-api-{$phpVersion}-{$osName}.zip";
        $steamPath = $this->releaseFile(
            "{$cacheKey}/{$steamLibName}",
            $phpVersion,
            static fn (string $name): bool => $name === $assetName,
            [$steamLibName, "buildroot/bin/{$steamLibName}"],
            $this->cacheDir . "/{$cacheKey}/{$steamLibName}",
        );
        if ($steamPath !== null) {
            $libs[] = $steamPath;
        } else {
            // Required: a game built against php-steamworks hard-imports the
            // Steam API library, so a missing one means the shipped binary
            // cannot start. Loud warning, mirroring d3dcompiler_47.dll.
            $this->log("No {$steamLibName} found for {$cacheKey} — the Steam API library is REQUIRED; the shipped binary will fail to start without it.");
        }

        return $libs;
    }

    /**
     * One build log line saying which php-vio a runtime contains, or that it
     * cannot be told ({@see RuntimeProbe}).
     *
     * @param list<string> $libs
     */
    public function describeRuntime(string $microSfx, array $libs, string $platform, string $arch): string
    {
        return "Runtime {$platform}-{$arch}: " . $this->probe->describe($microSfx, $libs, $platform, $arch);
    }

    /**
     * Why a build must not go on under the build image's old entry script, or
     * null. That script signs the cached macOS runtime in place before the build;
     * the resolver may then replace it with the published, unsigned one, and the
     * bundle does not start on Apple Silicon.
     */
    public static function outdatedHarness(string $platform, ?string $microSfxPath, bool $insideBuildImage, bool $harnessAnnounced): ?string
    {
        if ($platform !== 'macos' || $microSfxPath !== null || !$insideBuildImage || $harnessAnnounced) {
            return null;
        }
        return 'This build image is older than the engine: its build-all script does not sign a copy of the macOS ' .
            'runtime, so the bundle could ship unsigned. Rebuild the image from the engine you build with: ' .
            'docker build -f docker/Dockerfile.build -t phpolygon-build docker';
    }

    /** Cache folder name of one runtime; `docker/build-all.sh` relies on the resolver for it. */
    private static function cacheKey(string $platform, string $arch, string $variant, string $phpVersion): string
    {
        return $variant !== 'base'
            ? "{$platform}-{$arch}-{$variant}-php{$phpVersion}"
            : "{$platform}-{$arch}-php{$phpVersion}";
    }

    /** OS name as the runtime release's asset names spell it. */
    private static function releaseOsName(string $platform, string $arch): string
    {
        return match (true) {
            $platform === 'macos' && $arch === 'arm64'   => 'macos-aarch64',
            $platform === 'macos' && $arch === 'x86_64'  => 'macos-x86_64',
            $platform === 'linux' && $arch === 'arm64'   => 'linux-aarch64',
            $platform === 'linux' && $arch === 'x86_64'  => 'linux-x86_64',
            $platform === 'windows'                       => 'windows-x86_64',
            default                                       => "{$platform}-{$arch}",
        };
    }

    private static function defaultCacheDir(): string
    {
        $base = getenv('PHPOLYGON_HOME');
        if (!is_string($base) || $base === '') {
            $base = (getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir()) . '/.phpolygon';
        }
        return $base . '/build-cache';
    }

    /**
     * One file out of a zip asset of the runtime release, through the cache.
     *
     * @param callable(string): bool $isAsset picks the asset by its name
     * @param list<string> $members paths the file may have inside the zip
     * @return ?string $target, or null when the release publishes no such asset
     * @throws \RuntimeException when it cannot be verified or downloaded
     */
    private function releaseFile(string $label, string $phpVersion, callable $isAsset, array $members, string $target): ?string
    {
        $tag = "runtime-php{$phpVersion}";
        $source = "{$this->repository}@{$tag}";
        $token = self::token();
        $release = $this->assets->release("https://api.github.com/repos/{$this->repository}/releases/tags/{$tag}", $token);

        if (is_string($release)) {
            return $this->offlineFiles($label, $source, $release, [$target]) ? $target : null;
        }

        $asset = null;
        foreach (ReleaseAsset::fromRelease($release) as $candidate) {
            if ($isAsset($candidate->name)) {
                $asset = $candidate;
                break;
            }
        }
        if ($asset === null) {
            $this->log("Runtime {$label}: not published in {$source}" . (is_file($target) ? ' - the cached file is ignored' : ''));
            return null;
        }

        $reason = ReleaseAssetCache::staleReason($target, $source, $asset);
        if ($reason === null) {
            $this->log("Runtime {$label}: verified current ({$asset->name}, {$asset->describe()})");
            return $target;
        }

        $this->downloadZip($label, $source, $asset, [$target => $members], $token, $reason);
        return $target;
    }

    /**
     * dxcompiler.dll + dxil.dll for the target arch, as the latest DXC release
     * publishes them (cached like every release file). Both or nothing: DXC
     * without dxil.dll cannot sign the DXIL it produces, and php-vio then refuses
     * Shader Model 6 anyway.
     *
     * @return list<string>
     */
    private function resolveDxcDlls(string $cacheKey, string $arch): array
    {
        $dir = $this->cacheDir . "/{$cacheKey}/dxc";
        $label = "{$cacheKey}/dxc";
        $source = 'microsoft/DirectXShaderCompiler@latest';
        $paths = [$dir . '/dxcompiler.dll', $dir . '/dxil.dll'];
        $token = self::token();
        $release = $this->assets->release(self::DXC_RELEASE_URL, $token);

        if (is_string($release)) {
            return $this->offlineFiles($label, $source, $release, $paths) ? $paths : [];
        }

        $windows = self::dxcWindowsAsset($release)['name'] ?? null;
        $asset = null;
        foreach (ReleaseAsset::fromRelease($release) as $candidate) {
            if ($candidate->name === $windows) {
                $asset = $candidate;
                break;
            }
        }
        if ($asset === null) {
            $this->log("Runtime {$label}: the latest DXC release publishes no Windows archive");
            return [];
        }

        $reason = ReleaseAssetCache::staleReason($paths[0], $source, $asset)
            ?? ReleaseAssetCache::staleReason($paths[1], $source, $asset);
        if ($reason === null) {
            $this->log("Runtime {$label}: verified current ({$asset->name}, {$asset->describe()})");
            return $paths;
        }

        $targets = [];
        foreach (self::dxcZipEntries($arch) as $entry => $name) {
            $targets[$dir . '/' . $name] = [$entry];
        }
        $this->downloadZip($label, $source, $asset, $targets, $token, $reason);
        return $paths;
    }

    /**
     * The release index is unreachable: fail, or - offline mode only - allow the
     * cached files that carry an identity record of the same release.
     *
     * @param list<string> $files
     * @return bool true = use the cached files, false = offline and not cached
     */
    private function offlineFiles(string $label, string $source, string $why, array $files): bool
    {
        if (!ReleaseAssetCache::offlineAllowed()) {
            throw new \RuntimeException(
                "Cannot verify runtime {$label}: the release index of {$source} is unreachable ({$why}).\n" .
                "A cached file is not used unverified. A release being re-published is missing for a moment - " .
                "retry then. To build offline from the cache anyway, set " . ReleaseAssetCache::OFFLINE_ENV . '=1.'
            );
        }
        $records = [];
        foreach ($files as $file) {
            $record = is_file($file) ? ReleaseAssetCache::record($file) : null;
            if ($record === null || $record['source'] !== $source) {
                $this->log("WARNING: offline - runtime {$label} has no cached file with an identity record of {$source}");
                return false;
            }
            $records[] = $record;
        }
        $this->log(sprintf(
            'WARNING: %s is set - using the UNVERIFIED cached runtime %s (%s, asset %d, updated %s); a newer one may be published (%s)',
            ReleaseAssetCache::OFFLINE_ENV,
            $label,
            is_string($records[0]['name']) ? $records[0]['name'] : '?',
            is_int($records[0]['id']) ? $records[0]['id'] : 0,
            is_string($records[0]['updatedAt'] ?? null) ? $records[0]['updatedAt'] : 'unknown',
            $why,
        ));
        return true;
    }

    /**
     * Download a zip asset, verify it and cache the wanted members. The archive
     * goes to a temporary file and the members are streamed out of it: a runtime
     * is some 80 MB, more than a default memory_limit holds twice.
     *
     * @param array<string, list<string>> $targets cache file => paths it may have in the zip
     */
    private function downloadZip(string $label, string $source, ReleaseAsset $asset, array $targets, ?string $token, string $reason): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException("Cannot unpack {$asset->name}: the build PHP lacks the zip extension");
        }
        $this->log("Runtime {$label}: downloading {$asset->name} ({$reason})...");
        $tempFile = tempnam(sys_get_temp_dir(), 'phpolygon-runtime-');
        if ($tempFile === false) {
            throw new \RuntimeException('Cannot create a temporary file for the download');
        }
        try {
            $content = $this->assets->download($asset, $asset->downloadUrl, self::requestHeaders($asset->downloadUrl, $token), $token);
            if (file_put_contents($tempFile, $content) !== strlen($content)) {
                throw new \RuntimeException("Cannot write {$tempFile}");
            }
            unset($content);

            $zip = new \ZipArchive();
            if ($zip->open($tempFile) !== true) {
                throw new \RuntimeException("{$asset->name} is not a zip archive");
            }
            try {
                // All members are looked up first: nothing is cached from an archive that lacks one.
                $found = [];
                foreach ($targets as $target => $members) {
                    $found[$target] = self::zipMemberName($zip, $members)
                        ?? throw new \RuntimeException("{$asset->name} contains none of: " . implode(', ', $members));
                }
                foreach ($found as $target => $member) {
                    ReleaseAssetCache::store($target, $source, $asset, static fn (string $temp): bool => self::extract($zip, $member, $temp));
                    $this->log(sprintf('Runtime %s: downloaded and cached %s (%.1f MB; %s)', $label, $target, (int) filesize($target) / 1024 / 1024, $asset->describe()));
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * The name under which one of $members (`/` paths) is a non-empty entry of the
     * archive, also when the archive stores paths with backslashes (the DXC
     * release archives do), or null.
     *
     * @param list<string> $members
     */
    public static function zipMemberName(\ZipArchive $zip, array $members): ?string
    {
        foreach ($members as $member) {
            foreach ([$member, str_replace('/', '\\', $member)] as $name) {
                $stat = $zip->statName($name);
                if (is_array($stat) && $stat['size'] > 0) {
                    return $name;
                }
            }
        }
        return null;
    }

    private static function extract(\ZipArchive $zip, string $member, string $target): bool
    {
        $in = $zip->getStream($member);
        if ($in === false) {
            return false;
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            return false;
        }
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        $closed = fclose($out);
        $stat = $zip->statName($member);
        return $closed && is_array($stat) && $copied === $stat['size'];
    }

    private static function token(): ?string
    {
        return getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: null;
    }

    /**
     * Per-OS destination name for the Steam API library.
     */
    private function steamLibName(string $platform): string
    {
        return match ($platform) {
            'windows' => 'steam_api64.dll',
            'macos'   => 'libsteam_api.dylib',
            default   => 'libsteam_api.so',
        };
    }

    /**
     * The Windows binary archive of a DXC release (`dxc_YYYY_MM_DD.zip`); the
     * Linux tarball and the PDB archive are skipped.
     *
     * @param array<mixed> $release GitHub release JSON
     * @return array{name: string, url: string}|null
     */
    public static function dxcWindowsAsset(array $release): ?array
    {
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = is_string($asset['name'] ?? null) ? $asset['name'] : '';
            $url = is_string($asset['browser_download_url'] ?? null) ? $asset['browser_download_url'] : '';
            if ($url !== '' && preg_match('/^dxc_\d{4}_\d{2}_\d{2}\.zip$/', $name) === 1) {
                return ['name' => $name, 'url' => $url];
            }
        }
        return null;
    }

    /**
     * Zip entry -> file name of the two DLLs for a build arch.
     *
     * @return array<string, string>
     */
    public static function dxcZipEntries(string $arch): array
    {
        $dir = in_array(strtolower($arch), ['arm64', 'aarch64'], true) ? 'arm64' : 'x64';
        return [
            "bin/{$dir}/dxcompiler.dll" => 'dxcompiler.dll',
            "bin/{$dir}/dxil.dll" => 'dxil.dll',
        ];
    }

    /**
     * Headers of a GET request to $url. $token goes to the GitHub API only, where
     * anonymous calls are limited to 60 an hour per IP - and CI runners share IPs.
     * Downloads go without it: they redirect to another host, which must not get it.
     *
     * @return list<string>
     */
    public static function requestHeaders(string $url, ?string $token): array
    {
        $headers = [
            'User-Agent: PHPolygon-Build/1.0',
            'Accept: application/vnd.github+json',
        ];
        if ($token !== null && $token !== '' && parse_url($url, PHP_URL_HOST) === 'api.github.com') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * Cache a micro.sfx binary for future use
     */
    public function cache(string $sourcePath, string $platform, string $arch): string
    {
        $dir = $this->cacheDir . "/{$platform}-{$arch}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $target = $dir . '/micro.sfx';
        copy($sourcePath, $target);
        chmod($target, 0755);

        return $target;
    }

    public static function detectPlatform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Windows' => 'windows',
            default => 'linux',
        };
    }

    public static function detectArch(): string
    {
        $uname = php_uname('m');
        return match (true) {
            str_contains($uname, 'arm64'), str_contains($uname, 'aarch64') => 'arm64',
            default => 'x86_64',
        };
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
