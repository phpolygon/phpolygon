<?php

declare(strict_types=1);

namespace PHPolygon\Build;

class StaticPhpResolver
{
    private const GITHUB_REPO = 'hmennen90/static-php-cli';

    private string $cacheDir;

    /** @var callable|null */
    private $logger = null;

    public function __construct()
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        $this->cacheDir = $home . '/.phpolygon/build-cache';
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
     * Priority: explicit path > download from GitHub Release (if newer) > cached
     */
    public function resolve(?string $explicitPath, string $platform, string $arch, string $variant = 'base', string $phpVersion = '8.5'): string
    {
        // 1. Explicit path from CLI
        if ($explicitPath !== null) {
            if (!file_exists($explicitPath)) {
                throw new \RuntimeException("micro.sfx not found at: {$explicitPath}");
            }
            return $explicitPath;
        }

        $cacheKey = $variant !== 'base'
            ? "{$platform}-{$arch}-{$variant}-php{$phpVersion}"
            : "{$platform}-{$arch}-php{$phpVersion}";
        $cachedPath = $this->cacheDir . "/{$cacheKey}/micro.sfx";

        // 2. Check GitHub for a newer release, download if available
        $downloaded = $this->downloadIfNewer($platform, $arch, $variant, $cachedPath, $phpVersion);
        if ($downloaded !== null) {
            return $downloaded;
        }

        // 3. Fall back to cached version
        if (file_exists($cachedPath)) {
            $this->log("Using cached micro.sfx for {$cacheKey}");
            return $cachedPath;
        }

        throw new \RuntimeException(
            "No micro.sfx binary found for {$variant}/{$platform}-{$arch}.\n\n" .
            "Options:\n" .
            "  1. Provide one with --micro-sfx <path>\n" .
            "  2. Trigger the 'Build Game micro.sfx' workflow in " . self::GITHUB_REPO . "\n" .
            "  3. Try a different PHP version with --php-version 8.4 or 8.5\n" .
            "  4. Cache a pre-built binary:\n" .
            "     mkdir -p ~/.phpolygon/build-cache/{$cacheKey}\n" .
            "     cp /path/to/micro.sfx ~/.phpolygon/build-cache/{$cacheKey}/micro.sfx"
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
     * @return list<string> Absolute paths to runtime libs, empty if none needed
     */
    public function resolveRuntimeLibs(string $platform, string $arch, string $variant = 'base', string $phpVersion = '8.5'): array
    {
        $cacheKey = $variant !== 'base'
            ? "{$platform}-{$arch}-{$variant}-php{$phpVersion}"
            : "{$platform}-{$arch}-php{$phpVersion}";

        $libs = [];

        if ($platform === 'windows') {
            // vulkan-1.dll
            $vulkanPath = $this->cacheDir . "/{$cacheKey}/vulkan-1.dll";
            $downloaded = $this->downloadVulkanDllIfNewer($platform, $arch, $variant, $phpVersion, $vulkanPath);
            if ($downloaded !== null) {
                $libs[] = $downloaded;
            } elseif (file_exists($vulkanPath)) {
                $libs[] = $vulkanPath;
            } else {
                // vulkan-1.dll is optional — Vulkan backend just won't be available
                // if absent. Log once so the user knows.
                $this->log("No vulkan-1.dll found for {$cacheKey} — Vulkan backend will be unavailable in the shipped binary.");
            }

            // d3dcompiler_47.dll
            $d3dPath = $this->cacheDir . "/{$cacheKey}/d3dcompiler_47.dll";
            $downloaded = $this->downloadD3DCompilerDllIfNewer($platform, $arch, $variant, $phpVersion, $d3dPath);
            if ($downloaded !== null) {
                $libs[] = $downloaded;
            } elseif (file_exists($d3dPath)) {
                $libs[] = $d3dPath;
            } else {
                // d3dcompiler_47.dll is required for the d3d11/d3d12 backends. Without
                // it the shipped exe dies at startup with 0xC0000135.
                $this->log("No d3dcompiler_47.dll found for {$cacheKey} — the d3d11/d3d12 backends will fail to start (0xC0000135) on a clean Windows install.");
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
        $steamPath = $this->cacheDir . "/{$cacheKey}/{$steamLibName}";
        $downloaded = $this->downloadSteamApiLibIfNewer($platform, $arch, $phpVersion, $steamPath);
        if ($downloaded !== null) {
            $libs[] = $downloaded;
        } elseif (file_exists($steamPath)) {
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

    private function downloadSteamApiLibIfNewer(string $platform, string $arch, string $phpVersion, string $cachedPath): ?string
    {
        $releaseUrl = $this->findRuntimeRelease($phpVersion);
        if ($releaseUrl === null) {
            return null;
        }

        $json = $this->httpGet($releaseUrl);
        $release = $json !== null ? json_decode($json, true) : null;
        if (!is_array($release)) {
            return null;
        }

        $publishedAt = isset($release['published_at']) && is_string($release['published_at'])
            ? strtotime($release['published_at'])
            : null;

        if ($publishedAt !== false && $publishedAt !== null && file_exists($cachedPath)) {
            $cachedMtime = filemtime($cachedPath);
            if ($cachedMtime !== false && $cachedMtime >= $publishedAt) {
                return null;
            }
        }

        $osName = match (true) {
            $platform === 'macos' && $arch === 'arm64'   => 'macos-aarch64',
            $platform === 'macos' && $arch === 'x86_64'  => 'macos-x86_64',
            $platform === 'linux' && $arch === 'arm64'   => 'linux-aarch64',
            $platform === 'linux' && $arch === 'x86_64'  => 'linux-x86_64',
            $platform === 'windows'                       => 'windows-x86_64',
            default                                       => "{$platform}-{$arch}",
        };

        // No variant suffix: libsteam-api-<phpVersion>-<os>.zip
        $assetName = "libsteam-api-{$phpVersion}-{$osName}.zip";
        $libName = $this->steamLibName($platform);

        $downloadUrl = null;
        if (isset($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = isset($asset['name']) && is_string($asset['name']) ? $asset['name'] : '';
                if ($name === $assetName) {
                    $downloadUrl = isset($asset['browser_download_url']) && is_string($asset['browser_download_url'])
                        ? $asset['browser_download_url']
                        : null;
                    break;
                }
            }
        }

        if ($downloadUrl === null) {
            return null;
        }

        $this->log("Downloading {$assetName}...");
        /** @var string $downloadUrl */

        $tempFile = tempnam(sys_get_temp_dir(), 'phpolygon-steamlib-');
        if ($tempFile === false) {
            return null;
        }

        $content = $this->httpGet($downloadUrl);
        if ($content === null) {
            @unlink($tempFile);
            return null;
        }
        file_put_contents($tempFile, $content);

        $actualFile = null;
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                $extractDir = sys_get_temp_dir() . '/phpolygon-steamlib-extract-' . getmypid();
                $zip->extractTo($extractDir);
                $zip->close();
                foreach ([$libName, "buildroot/bin/{$libName}"] as $candidate) {
                    if (file_exists($extractDir . '/' . $candidate)) {
                        $actualFile = $extractDir . '/' . $candidate;
                        break;
                    }
                }
            }
        }

        if ($actualFile === null) {
            @unlink($tempFile);
            return null;
        }

        $cachedDir = dirname($cachedPath);
        if (!is_dir($cachedDir)) {
            mkdir($cachedDir, 0755, true);
        }
        copy($actualFile, $cachedPath);

        @unlink($tempFile);
        if (isset($extractDir) && is_dir($extractDir)) {
            $this->removeDir($extractDir);
        }

        $size = filesize($cachedPath);
        $this->log(sprintf("Downloaded and cached: %s (%.1f KB)", $cachedPath, $size / 1024));

        return $cachedPath;
    }

    private function downloadVulkanDllIfNewer(string $platform, string $arch, string $variant, string $phpVersion, string $cachedPath): ?string
    {
        $releaseUrl = $this->findRuntimeRelease($phpVersion);
        if ($releaseUrl === null) {
            return null;
        }

        $json = $this->httpGet($releaseUrl);
        $release = $json !== null ? json_decode($json, true) : null;
        if (!is_array($release)) {
            return null;
        }

        $publishedAt = isset($release['published_at']) && is_string($release['published_at'])
            ? strtotime($release['published_at'])
            : null;

        if ($publishedAt !== false && $publishedAt !== null && file_exists($cachedPath)) {
            $cachedMtime = filemtime($cachedPath);
            if ($cachedMtime !== false && $cachedMtime >= $publishedAt) {
                return null;
            }
        }

        $osName = match (true) {
            $platform === 'windows' => 'windows-x86_64',
            default                 => "{$platform}-{$arch}",
        };

        $prefix = "vulkan-1-dll-{$variant}-{$phpVersion}-";
        $suffix = "-{$osName}.zip";

        $downloadUrl = null;
        $matchedAsset = null;
        if (isset($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = isset($asset['name']) && is_string($asset['name']) ? $asset['name'] : '';
                if (str_starts_with($name, $prefix) && str_ends_with($name, $suffix)) {
                    $downloadUrl = isset($asset['browser_download_url']) && is_string($asset['browser_download_url'])
                        ? $asset['browser_download_url']
                        : null;
                    $matchedAsset = $name;
                    break;
                }
            }
        }

        if ($downloadUrl === null) {
            return null;
        }

        $this->log("Downloading {$matchedAsset}...");
        /** @var string $downloadUrl */

        $tempFile = tempnam(sys_get_temp_dir(), 'phpolygon-vkdll-');
        if ($tempFile === false) {
            return null;
        }

        $content = $this->httpGet($downloadUrl);
        if ($content === null) {
            @unlink($tempFile);
            return null;
        }
        file_put_contents($tempFile, $content);

        $actualFile = null;
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                $extractDir = sys_get_temp_dir() . '/phpolygon-vkdll-extract-' . getmypid();
                $zip->extractTo($extractDir);
                $zip->close();
                foreach (['vulkan-1.dll', 'buildroot/bin/vulkan-1.dll'] as $candidate) {
                    if (file_exists($extractDir . '/' . $candidate)) {
                        $actualFile = $extractDir . '/' . $candidate;
                        break;
                    }
                }
            }
        }

        if ($actualFile === null) {
            @unlink($tempFile);
            return null;
        }

        $cachedDir = dirname($cachedPath);
        if (!is_dir($cachedDir)) {
            mkdir($cachedDir, 0755, true);
        }
        copy($actualFile, $cachedPath);

        @unlink($tempFile);
        if (isset($extractDir) && is_dir($extractDir)) {
            $this->removeDir($extractDir);
        }

        $size = filesize($cachedPath);
        $this->log(sprintf("Downloaded and cached: %s (%.1f KB)", $cachedPath, $size / 1024));

        return $cachedPath;
    }

    /** Release feed of the DirectX Shader Compiler (MIT/LLVM licensed, dxil.dll redistributable). */
    private const string DXC_RELEASE_URL = 'https://api.github.com/repos/microsoft/DirectXShaderCompiler/releases/latest';

    /**
     * dxcompiler.dll + dxil.dll for the target arch, from the build cache or the
     * latest DXC release. Both or nothing: DXC without dxil.dll cannot sign the
     * DXIL it produces, and php-vio then refuses Shader Model 6 anyway.
     *
     * @return list<string>
     */
    private function resolveDxcDlls(string $cacheKey, string $arch): array
    {
        $dir = $this->cacheDir . "/{$cacheKey}/dxc";
        $paths = [$dir . '/dxcompiler.dll', $dir . '/dxil.dll'];
        $present = static fn (): bool => is_file($paths[0]) && is_file($paths[1]);
        if (!$present()) {
            $this->downloadDxc($dir, $arch);
        }
        return $present() ? $paths : [];
    }

    private function downloadDxc(string $dir, string $arch): void
    {
        $json = $this->httpGet(self::DXC_RELEASE_URL);
        $release = $json !== null ? json_decode($json, true) : null;
        $asset = is_array($release) ? self::dxcWindowsAsset($release) : null;
        if ($asset === null || !class_exists(\ZipArchive::class)) {
            return;
        }

        $this->log("Downloading {$asset['name']}...");
        $content = $this->httpGet($asset['url']);
        $tempFile = tempnam(sys_get_temp_dir(), 'phpolygon-dxc-');
        if ($content === null || $tempFile === false) {
            return;
        }
        file_put_contents($tempFile, $content);

        $zip = new \ZipArchive();
        if ($zip->open($tempFile) === true) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            foreach (self::dxcZipEntries($arch) as $entry => $name) {
                $data = self::zipEntry($zip, $entry);
                if ($data !== null) {
                    file_put_contents($dir . '/' . $name, $data);
                }
            }
            $zip->close();
        }
        @unlink($tempFile);
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
     * A non-empty zip entry by its `/` path, also when the archive stores the path
     * with backslashes (the DXC release archives do), or null.
     */
    public static function zipEntry(\ZipArchive $zip, string $entry): ?string
    {
        foreach ([$entry, str_replace('/', '\\', $entry)] as $name) {
            $data = $zip->getFromName($name);
            if (is_string($data) && $data !== '') {
                return $data;
            }
        }
        return null;
    }

    private function downloadD3DCompilerDllIfNewer(string $platform, string $arch, string $variant, string $phpVersion, string $cachedPath): ?string
    {
        $releaseUrl = $this->findRuntimeRelease($phpVersion);
        if ($releaseUrl === null) {
            return null;
        }

        $json = $this->httpGet($releaseUrl);
        $release = $json !== null ? json_decode($json, true) : null;
        if (!is_array($release)) {
            return null;
        }

        $publishedAt = isset($release['published_at']) && is_string($release['published_at'])
            ? strtotime($release['published_at'])
            : null;

        if ($publishedAt !== false && $publishedAt !== null && file_exists($cachedPath)) {
            $cachedMtime = filemtime($cachedPath);
            if ($cachedMtime !== false && $cachedMtime >= $publishedAt) {
                return null;
            }
        }

        $osName = match (true) {
            $platform === 'windows' => 'windows-x86_64',
            default                 => "{$platform}-{$arch}",
        };

        $prefix = "d3dcompiler-47-dll-{$variant}-{$phpVersion}-";
        $suffix = "-{$osName}.zip";

        $downloadUrl = null;
        $matchedAsset = null;
        if (isset($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = isset($asset['name']) && is_string($asset['name']) ? $asset['name'] : '';
                if (str_starts_with($name, $prefix) && str_ends_with($name, $suffix)) {
                    $downloadUrl = isset($asset['browser_download_url']) && is_string($asset['browser_download_url'])
                        ? $asset['browser_download_url']
                        : null;
                    $matchedAsset = $name;
                    break;
                }
            }
        }

        if ($downloadUrl === null) {
            return null;
        }

        $this->log("Downloading {$matchedAsset}...");
        /** @var string $downloadUrl */

        $tempFile = tempnam(sys_get_temp_dir(), 'phpolygon-d3ddll-');
        if ($tempFile === false) {
            return null;
        }

        $content = $this->httpGet($downloadUrl);
        if ($content === null) {
            @unlink($tempFile);
            return null;
        }
        file_put_contents($tempFile, $content);

        $actualFile = null;
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                $extractDir = sys_get_temp_dir() . '/phpolygon-d3ddll-extract-' . getmypid();
                $zip->extractTo($extractDir);
                $zip->close();
                foreach (['d3dcompiler_47.dll', 'buildroot/bin/d3dcompiler_47.dll'] as $candidate) {
                    if (file_exists($extractDir . '/' . $candidate)) {
                        $actualFile = $extractDir . '/' . $candidate;
                        break;
                    }
                }
            }
        }

        if ($actualFile === null) {
            @unlink($tempFile);
            return null;
        }

        $cachedDir = dirname($cachedPath);
        if (!is_dir($cachedDir)) {
            mkdir($cachedDir, 0755, true);
        }
        copy($actualFile, $cachedPath);

        @unlink($tempFile);
        if (isset($extractDir) && is_dir($extractDir)) {
            $this->removeDir($extractDir);
        }

        $size = filesize($cachedPath);
        $this->log(sprintf("Downloaded and cached: %s (%.1f KB)", $cachedPath, $size / 1024));

        return $cachedPath;
    }

    /**
     * Check if a newer release exists on GitHub than the cached binary.
     * Downloads and caches if newer; returns null if cache is up-to-date or offline.
     */
    private function downloadIfNewer(string $platform, string $arch, string $variant, string $cachedPath, string $phpVersion): ?string
    {
        $releaseUrl = $this->findRuntimeRelease($phpVersion);
        if ($releaseUrl === null) {
            return null;
        }

        $json = $this->httpGet($releaseUrl);
        $release = $json !== null ? json_decode($json, true) : null;
        if (!is_array($release)) {
            return null;
        }

        // Compare release date with cached file mtime
        $publishedAt = isset($release['published_at']) && is_string($release['published_at'])
            ? strtotime($release['published_at'])
            : null;

        if ($publishedAt !== false && $publishedAt !== null && file_exists($cachedPath)) {
            $cachedMtime = filemtime($cachedPath);
            if ($cachedMtime !== false && $cachedMtime >= $publishedAt) {
                return null; // cache is up-to-date
            }
            $this->log("Newer release found, updating cache...");
        }

        /** @var array<string, mixed> $release */
        return $this->downloadFromReleaseData($release, $platform, $arch, $variant, $phpVersion);
    }

    /**
     * Download micro.sfx from a parsed GitHub release response.
     *
     * @param array<string, mixed> $release
     */
    private function downloadFromReleaseData(array $release, string $platform, string $arch, string $variant, string $phpVersion = '8.5'): ?string
    {
        $osName = match (true) {
            $platform === 'macos' && $arch === 'arm64'   => 'macos-aarch64',
            $platform === 'macos' && $arch === 'x86_64'  => 'macos-x86_64',
            $platform === 'linux' && $arch === 'arm64'   => 'linux-aarch64',
            $platform === 'linux' && $arch === 'x86_64'  => 'linux-x86_64',
            $platform === 'windows'                       => 'windows-x86_64',
            default                                       => "{$platform}-{$arch}",
        };

        $downloadUrl = null;
        $matchedAsset = null;
        $prefix = "micro-sfx-{$variant}-";
        $suffix = "-{$osName}.zip";

        if (isset($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = isset($asset['name']) && is_string($asset['name']) ? $asset['name'] : '';
                if (str_starts_with($name, $prefix) && str_ends_with($name, $suffix)) {
                    $downloadUrl = isset($asset['browser_download_url']) && is_string($asset['browser_download_url'])
                        ? $asset['browser_download_url']
                        : null;
                    $matchedAsset = $name;
                    break;
                }
            }
        }

        if ($downloadUrl === null) {
            $this->log("No matching micro.sfx asset found for {$variant}/{$osName}");
            return null;
        }

        $this->log("Downloading {$matchedAsset}...");

        /** @var string $downloadUrl */
        $tempFile = tempnam(sys_get_temp_dir(), 'phpolygon-micro-');
        if ($tempFile === false) {
            return null;
        }

        $content = $this->httpGet($downloadUrl);
        if ($content === null) {
            @unlink($tempFile);
            return null;
        }

        file_put_contents($tempFile, $content);

        // Extract micro.sfx from zip
        $actualFile = $tempFile;
        if ($matchedAsset !== null && str_ends_with($matchedAsset, '.zip') && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                $extractDir = sys_get_temp_dir() . '/phpolygon-micro-extract-' . getmypid();
                $zip->extractTo($extractDir);
                $zip->close();
                foreach (['micro.sfx', 'micro.sfx.exe'] as $binName) {
                    $candidate = $extractDir . '/' . $binName;
                    if (!file_exists($candidate)) {
                        $candidate = $extractDir . '/buildroot/bin/' . $binName;
                    }
                    if (file_exists($candidate)) {
                        $actualFile = $candidate;
                        break;
                    }
                }
            }
        }

        $cacheKey = $variant !== 'base'
            ? "{$platform}-{$arch}-{$variant}-php{$phpVersion}"
            : "{$platform}-{$arch}-php{$phpVersion}";
        $cachedDir = $this->cacheDir . "/{$cacheKey}";
        if (!is_dir($cachedDir)) {
            mkdir($cachedDir, 0755, true);
        }
        $cachedPath = $cachedDir . '/micro.sfx';
        copy($actualFile, $cachedPath);
        chmod($cachedPath, 0755);

        // Cleanup
        @unlink($tempFile);
        if (isset($extractDir) && is_dir($extractDir)) {
            $this->removeDir($extractDir);
        }

        $size = filesize($cachedPath);
        $this->log(sprintf("Downloaded and cached: %s (%.1f MB)", $cachedPath, $size / 1024 / 1024));

        return $cachedPath;
    }

    private function findRuntimeRelease(string $phpVersion): ?string
    {
        $tag = "runtime-php{$phpVersion}";
        $url = "https://api.github.com/repos/" . self::GITHUB_REPO . "/releases/tags/{$tag}";
        $json = $this->httpGet($url);
        if ($json === null) {
            $this->log("No release found for tag {$tag}");
            return null;
        }

        $release = json_decode($json, true);
        if (!is_array($release) || !isset($release['url'])) {
            return null;
        }

        return is_string($release['url']) ? $release['url'] : null;
    }

    private function removeDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: PHPolygon-Build/1.0',
                    'Accept: application/vnd.github+json',
                ],
                'timeout' => 30,
                'follow_location' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false ? $result : null;
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
