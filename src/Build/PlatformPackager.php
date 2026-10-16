<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use PHPolygon\Runtime\BundleExtractor;

class PlatformPackager
{
    private BuildConfig $config;

    public function __construct(BuildConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Package the combined binary for the target platform
     *
     * @param list<string> $runtimeLibs Extra platform-specific libs resolved by
     *                                  StaticPhpResolver / NativeUpscalerResolver
     *                                  (e.g. vulkan-1.dll, amd_fidelityfx_dx12.dll)
     * @param string $thirdPartyNotices Text of {@see ThirdPartyNotices::FILE},
     *                                  written next to the binary unless ''
     * @return string Path to the output directory/bundle
     */
    public function package(string $binaryPath, string $outputDir, string $platform, string $variant = 'base', array $runtimeLibs = [], string $thirdPartyNotices = '', ?string $pharPath = null): string
    {
        $output = match ($platform) {
            'macos' => $this->packageMacOS($binaryPath, $outputDir, $variant, $runtimeLibs, $pharPath),
            'windows' => $this->packageFlat($binaryPath, $outputDir, '.exe', 'windows', $variant, $runtimeLibs),
            'linux' => $this->packageFlat($binaryPath, $outputDir, '', 'linux', $variant, $runtimeLibs),
            default => throw new \RuntimeException("Unsupported platform: {$platform}"),
        };
        if ($thirdPartyNotices !== '') {
            $dir = $platform === 'macos' ? $output . '/Contents/Resources' : $output;
            file_put_contents($dir . '/' . ThirdPartyNotices::FILE, $thirdPartyNotices);
        }
        return $output;
    }

    /**
     * File name of the PHAR in a macOS bundle's Contents/Resources: the binary
     * carries only a launcher ({@see PharBuilder::generateMacLauncher()}), so the
     * arm64 and the x86_64 binary of a universal bundle share one archive.
     */
    public function macPharFile(): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->config->name)) . '.phar';
    }

    /**
     * macOS layout: Contents/MacOS/<name> (runtime + launcher, or runtime + PHAR
     * without $pharPath), Contents/Resources/<game>.phar, the external resources
     * and the archive's assets/ and resources/ pre-extracted next to them with
     * the build's marker - the stub then finds them current and never writes
     * into the signed bundle.
     *
     * @param list<string> $runtimeLibs
     */
    private function packageMacOS(string $binaryPath, string $outputDir, string $variant = 'base', array $runtimeLibs = [], ?string $pharPath = null): string
    {
        $name = $this->config->name;
        $appDir = $outputDir . "/{$name}.app";
        $contentsDir = $appDir . '/Contents';
        $macosDir = $contentsDir . '/MacOS';
        $resourcesDir = $contentsDir . '/Resources';

        foreach ([$macosDir, $resourcesDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Copy binary
        copy($binaryPath, $macosDir . '/' . $name);
        chmod($macosDir . '/' . $name, 0755);

        // Copy bundle libs (e.g. native .dylib files) next to binary
        $this->copyBundleLibs($macosDir, 'macos');

        // Copy resolver-provided runtime libs
        $this->copyRuntimeLibs($macosDir, $runtimeLibs);

        // Generate Info.plist
        $this->writeInfoPlist($contentsDir);

        // Copy external resources to Resources/
        $this->copyExternalResources($resourcesDir);

        if ($pharPath !== null) {
            copy($pharPath, $resourcesDir . '/' . $this->macPharFile());
            $this->preExtract($pharPath, $resourcesDir);
        }

        // Copy icon if configured
        $macosConfig = $this->config->platforms['macos'] ?? [];
        if (isset($macosConfig['icon']) && is_string($macosConfig['icon'])) {
            $iconPath = $this->config->projectRoot . '/' . $macosConfig['icon'];
            if (file_exists($iconPath)) {
                copy($iconPath, $resourcesDir . '/' . basename($iconPath));
            }
        }

        return $appDir;
    }

    /**
     * @param list<string> $runtimeLibs
     */
    private function packageFlat(string $binaryPath, string $outputDir, string $extension, string $platform, string $variant = 'base', array $runtimeLibs = []): string
    {
        $name = $this->config->name;
        $dir = $outputDir . '/' . $name;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $targetName = $name . $extension;
        copy($binaryPath, $dir . '/' . $targetName);
        chmod($dir . '/' . $targetName, 0755);

        // Copy bundle libs next to binary
        $this->copyBundleLibs($dir, $platform);

        // Copy resolver-provided runtime libs (vulkan-1.dll etc.)
        $this->copyRuntimeLibs($dir, $runtimeLibs);

        // Copy external resources alongside binary
        $this->copyExternalResources($dir);

        return $dir;
    }

    /**
     * Copy runtime libraries resolved by StaticPhpResolver (e.g. vulkan-1.dll
     * on Windows) to sit next to the binary.
     *
     * @param list<string> $runtimeLibs
     */
    private function copyRuntimeLibs(string $targetDir, array $runtimeLibs): void
    {
        foreach ($runtimeLibs as $lib) {
            if (file_exists($lib)) {
                copy($lib, $targetDir . '/' . basename($lib));
            }
        }
    }

    private function writeInfoPlist(string $contentsDir): void
    {
        $name = $this->config->name;
        $identifier = $this->config->identifier;
        $version = $this->config->version;
        $macosConfig = $this->config->platforms['macos'] ?? [];
        $minVersion = isset($macosConfig['minimumVersion']) && is_string($macosConfig['minimumVersion'])
            ? $macosConfig['minimumVersion']
            : '12.0';

        $iconFile = '';
        if (isset($macosConfig['icon']) && is_string($macosConfig['icon'])) {
            $iconFile = basename($macosConfig['icon']);
        }

        $plist = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key>
    <string>{$name}</string>
    <key>CFBundleDisplayName</key>
    <string>{$name}</string>
    <key>CFBundleIdentifier</key>
    <string>{$identifier}</string>
    <key>CFBundleVersion</key>
    <string>{$version}</string>
    <key>CFBundleShortVersionString</key>
    <string>{$version}</string>
    <key>CFBundleExecutable</key>
    <string>{$name}</string>
    <key>CFBundlePackageType</key>
    <string>APPL</string>
    <key>CFBundleIconFile</key>
    <string>{$iconFile}</string>
    <key>LSMinimumSystemVersion</key>
    <string>{$minVersion}</string>
    <key>NSHighResolutionCapable</key>
    <true/>
    <key>NSSupportsAutomaticGraphicsSwitching</key>
    <true/>
</dict>
</plist>
XML;

        file_put_contents($contentsDir . '/Info.plist', $plist);
    }

    private function copyBundleLibs(string $targetDir, string $platform): void
    {
        $libs = $this->config->bundleLibs[$platform] ?? [];
        foreach ($libs as $lib) {
            $src = $lib['src'];
            $optional = $lib['optional'] ?? false;

            if ($src !== '' && $src[0] !== '/') {
                $src = $this->config->projectRoot . '/' . $src;
            }

            if (!file_exists($src)) {
                if ($optional) {
                    continue;
                }
                throw new \RuntimeException("Bundle lib not found: {$src}");
            }
            copy($src, $targetDir . '/' . basename($src));
        }
    }

    /**
     * Unpack the PHAR's assets/ and resources/ into $resourcesDir with the
     * build's marker ({@see BundleExtractor}), so a read-only install starts
     * without extracting anything.
     */
    private function preExtract(string $pharPath, string $resourcesDir): void
    {
        $buildId = PharBuilder::readBuildId($pharPath);
        if ($buildId === null) {
            return; // an archive from an older engine: the stub extracts as before
        }
        $base = 'phar://' . str_replace('\\', '/', $pharPath);
        foreach (['assets', 'resources'] as $part) {
            if (is_dir($base . '/' . $part)) {
                BundleExtractor::sync($base . '/' . $part, $resourcesDir . '/' . $part, $buildId);
            }
        }
    }

    /**
     * Copy one build.json `resources.external` path (folder or file) of the
     * project to $dst, minus `resources.exclude`. A missing path is skipped.
     */
    public static function copyExternalPath(BuildConfig $config, string $resourcePath, string $dst): void
    {
        $exclude = new PathGlob($config->resourcesExclude);
        $src = $config->projectRoot . '/' . $resourcePath;
        if ($exclude->matches($resourcePath)) {
            return;
        }
        if (is_file($src)) {
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0755, true);
            }
            copy($src, $dst);
            return;
        }
        if (is_dir($src)) {
            if (!is_dir($dst)) {
                mkdir($dst, 0755, true);
            }
            FileTree::copyFiltered($src, $dst, static fn (string $relative): bool => $exclude->matches($resourcePath . '/' . $relative));
        }
    }

    /**
     * Copy build.json `resources.external` (directories or single files) next to
     * the binary, minus `resources.exclude`.
     */
    private function copyExternalResources(string $targetDir): void
    {
        foreach ($this->config->externalResources as $resourcePath) {
            self::copyExternalPath($this->config, $resourcePath, $targetDir . '/' . $resourcePath);
        }

        // build.json resources.trimPython: slim embedded Python installs in the
        // package only - the project's copies keep pip and friends.
        foreach ($this->config->trimPython as $trim) {
            foreach (glob($targetDir . '/' . $trim['path'], GLOB_ONLYDIR) ?: [] as $pythonRoot) {
                PythonRuntimeTrimmer::trim($pythonRoot, $trim['groups'], $trim['remove'], $trim['keep']);
            }
        }

        // Copy engine branding assets (splash screen logo) so the engine
        // can find them at runtime via PHPOLYGON_PATH_RESOURCES/branding/.
        $engineBranding = __DIR__ . '/../../resources/branding';
        if (is_dir($engineBranding)) {
            $dst = $targetDir . '/resources/branding';
            FileTree::copy($engineBranding, $dst);
        }
    }
}
