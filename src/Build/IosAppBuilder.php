<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Builds an iOS / iPadOS .app from a staged game tree + an embed libphp.a.
 *
 * Unlike the desktop platforms (which combine a phpmicro self-extracting
 * binary with a phar), iOS links the game's PHP runtime as a static library
 * (libphp.a, produced by static-php-cli's ios-* target) into a thin
 * UIKit/Metal wrapper, then builds the app with Xcode.
 *
 * Pipeline:
 *   1. Lay out an Xcode project from the bundled template (Source/*.m, the
 *      ObjC<->PHP bridge) plus the staged game tree under App/.
 *   2. Generate ios_main.php (entry-script + writable-path setup) and a
 *      project.yml describing signing, frameworks, and the libphp link line.
 *   3. xcodegen -> xcodebuild -> .app.
 *
 * Game-specific values come from build.json's platforms.ios block:
 *   bundleId, team, deploymentTarget, orientations, libs (link flags).
 */
class IosAppBuilder
{
    private BuildConfig $config;
    private string $templateDir;

    /** @var callable|null */
    private $logger = null;

    /**
     * Default static link order for a vio + mbstring + zip game. PHP first,
     * then its lib deps; order matters for static archives (php -> xml2 ->
     * iconv, spirv-cross C++ core last). Overridable via platforms.ios.libs.
     *
     * @var list<string>
     */
    private const DEFAULT_LIBS = [
        '-lphp', '-lzip', '-lxml2', '-liconv', '-lcharset',
        // ICU (ext-intl): consumer libs first, then the data archive. ICU is
        // C++ so -lc++ below covers its runtime. iOS has no system ICU, so the
        // static-php-cli cross-built archives must be linked explicitly.
        '-licui18n', '-licuio', '-licuuc', '-licudata',
        '-lspirv-cross-c', '-lspirv-cross-glsl', '-lspirv-cross-msl',
        '-lspirv-cross-hlsl', '-lspirv-cross-reflect', '-lspirv-cross-cpp',
        '-lspirv-cross-core', '-lglslang', '-lSPIRV',
        '-lglslang-default-resource-limits', '-lMachineIndependent',
        '-lGenericCodeGen', '-lOSDependent', '-lresolv', '-lc++', '-lz',
        '-ObjC',
    ];

    /** Apple frameworks the wrapper links (never embedded - SDK-provided). */
    private const FRAMEWORKS = [
        'Foundation', 'UIKit', 'Metal', 'QuartzCore', 'CoreGraphics',
        'AVFoundation', 'AudioToolbox', 'CoreAudio', 'CoreFoundation',
    ];

    public function __construct(BuildConfig $config)
    {
        $this->config = $config;
        $this->templateDir = __DIR__ . '/ios/template';
    }

    /**
     * @param callable(string): void $logger
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    private function log(string $msg): void
    {
        if ($this->logger !== null) {
            ($this->logger)($msg);
        }
    }

    /**
     * Build the .app.
     *
     * @param string $stagingDir Staged game tree (PharBuilder::stage output)
     * @param string $libphpDir  static-php-cli buildroot (lib/libphp.a + include/php)
     * @param string $outputDir  Where to place the resulting .app
     * @param string $slice      ios-arm64 | ios-simulator-arm64 | ios-simulator-x86_64
     * @return string Path to the built .app
     */
    public function build(string $stagingDir, string $libphpDir, string $outputDir, string $slice = 'ios-arm64'): string
    {
        $this->requireTool('xcodegen');
        $this->requireTool('xcodebuild');

        $libphp = $libphpDir . '/lib/libphp.a';
        if (!file_exists($libphp)) {
            throw new \RuntimeException(
                "libphp.a not found at {$libphp}.\n" .
                "Build it first: SPC_TARGET={$slice} bin/spc build <exts> --build-embed"
            );
        }

        // Work in a temp project dir so repeated builds stay clean.
        $work = sys_get_temp_dir() . '/phpolygon-ios-' . getmypid();
        $this->removeDir($work);
        mkdir($work . '/Source', 0755, true);

        // 1. Template ObjC sources.
        foreach (glob($this->templateDir . '/Source/*') ?: [] as $src) {
            copy($src, $work . '/Source/' . basename($src));
        }

        // 2. Staged game tree -> App/, plus the generated entry shim.
        $appDir = $work . '/App';
        $this->copyDir($stagingDir, $appDir);
        // PharBuilder excludes externalResources from the staged tree (on
        // desktop they sit next to the binary). The iOS bundle has no such
        // sidecar - App/resources IS the resource root - so fold them back in,
        // otherwise large assets like audio are silently missing.
        $this->stageExternalResources($appDir);
        $this->writeEntryShim($appDir . '/ios_main.php');

        // 3. project.yml.
        file_put_contents($work . '/project.yml', $this->generateProjectYaml($libphpDir, $slice));

        // 4. xcodegen.
        $this->log('xcodegen generate');
        $this->run("cd " . escapeshellarg($work) . " && xcodegen generate");

        // 5. xcodebuild.
        $projectName = $this->sanitizedName();
        [$sdk, $arch] = $this->sdkAndArch($slice);
        $signArgs = $this->signingArgs();
        $this->log("xcodebuild ({$sdk}, {$arch})");
        $this->run(
            "cd " . escapeshellarg($work) . " && " .
            "xcodebuild -project {$projectName}.xcodeproj -scheme {$projectName} " .
            "-sdk {$sdk} -configuration Debug ARCHS={$arch} ONLY_ACTIVE_ARCH=YES " .
            "ENABLE_DEBUG_DYLIB=NO {$signArgs} -derivedDataPath " . escapeshellarg($work . '/DerivedData') . " build"
        );

        // 6. Locate + copy out the .app.
        $built = $this->findBuiltApp($work . '/DerivedData', $sdk);
        if ($built === null) {
            throw new \RuntimeException('xcodebuild succeeded but no .app was found under DerivedData');
        }
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        $dest = $outputDir . '/' . basename($built);
        $this->removeDir($dest);
        $this->copyDir($built, $dest);
        $this->log("app: {$dest}");

        return $dest;
    }

    /**
     * Copy externalResources (and engine branding) into the App/ tree so the
     * read-only bundle is self-contained. Mirrors PlatformPackager's
     * copyExternalResources, but targets the in-bundle resource root instead
     * of a sidecar directory.
     */
    private function stageExternalResources(string $appDir): void
    {
        foreach ($this->config->externalResources as $resourcePath) {
            $src = $this->config->projectRoot . '/' . $resourcePath;
            if (!is_dir($src)) {
                continue;
            }
            $dst = $appDir . '/' . $resourcePath;
            if (!is_dir($dst)) {
                $this->log("staging external resource: {$resourcePath}");
                $this->copyDir($src, $dst);
            }
        }

        // Engine branding (splash logo) lives in the engine, not the game.
        $branding = dirname(__DIR__, 2) . '/resources/branding';
        if (is_dir($branding) && !is_dir($appDir . '/resources/branding')) {
            $this->copyDir($branding, $appDir . '/resources/branding');
        }
    }

    /** Generate App/ios_main.php from the template, substituting the entry. */
    private function writeEntryShim(string $path): void
    {
        $tmpl = file_get_contents($this->templateDir . '/ios_main.php');
        if ($tmpl === false) {
            throw new \RuntimeException('iOS template ios_main.php missing');
        }
        $tmpl = str_replace('{{ENTRY_SCRIPT}}', $this->config->entry, $tmpl);
        file_put_contents($path, $tmpl);
    }

    private function generateProjectYaml(string $libphpDir, string $slice): string
    {
        $name = $this->sanitizedName();
        $ios = $this->config->platforms['ios'] ?? [];
        $bundleId = (string) ($ios['bundleId'] ?? $this->config->identifier);
        $team = (string) ($ios['team'] ?? '');
        $deploy = (string) ($ios['deploymentTarget'] ?? '14.0');
        $display = $this->config->name;
        $version = $this->config->version;

        /** @var list<string> $orient */
        $orient = $ios['orientations'] ?? ['LandscapeLeft', 'LandscapeRight'];
        $orientYaml = '';
        foreach ($orient as $o) {
            $orientYaml .= "          - UIInterfaceOrientation{$o}\n";
        }

        /** @var list<string> $libs */
        $libs = $ios['libs'] ?? self::DEFAULT_LIBS;
        $ldYaml = '';
        foreach ($libs as $l) {
            $ldYaml .= "          - " . $this->yamlStr($l) . "\n";
        }

        $fwYaml = '';
        foreach (self::FRAMEWORKS as $fw) {
            $fwYaml .= "      - framework: {$fw}.framework\n        embed: false\n";
        }

        $teamLine = $team !== '' ? "    DEVELOPMENT_TEAM: {$team}\n" : '';

        // SPC_BUILDROOT is absolute so the project can live in a temp dir.
        $buildroot = rtrim($libphpDir, '/');

        return <<<YAML
name: {$name}
options:
  deploymentTarget:
    iOS: "{$deploy}"
settings:
  base:
    ENABLE_BITCODE: NO
    CLANG_ENABLE_OBJC_ARC: YES
    SUPPORTS_MACCATALYST: NO
    TARGETED_DEVICE_FAMILY: "1,2"
    CODE_SIGN_STYLE: Automatic
{$teamLine}    ENABLE_DEBUG_DYLIB: NO
    SPC_BUILDROOT: "{$buildroot}"
targets:
  {$name}:
    type: application
    platform: iOS
    sources:
      - path: Source
      - path: App
        type: folder
    info:
      path: Source/Info.plist
      properties:
        CFBundleDisplayName: {$this->yamlStr($display)}
        CFBundleShortVersionString: "{$version}"
        LSRequiresIPhoneOS: true
        ITSAppUsesNonExemptEncryption: false
        UISupportedInterfaceOrientations:
{$orientYaml}        UISupportedInterfaceOrientations~ipad:
{$orientYaml}        UILaunchScreen:
          UIColorName: ""
        UIApplicationSceneManifest:
          UIApplicationSupportsMultipleScenes: false
          UISceneConfigurations:
            UIWindowSceneSessionRoleApplication:
              - UISceneConfigurationName: Default Configuration
                UISceneDelegateClassName: SceneDelegate
    settings:
      base:
        PRODUCT_BUNDLE_IDENTIFIER: {$bundleId}
        INFOPLIST_FILE: Source/Info.plist
        HEADER_SEARCH_PATHS:
          - "\$(SPC_BUILDROOT)/include/php"
          - "\$(SPC_BUILDROOT)/include/php/main"
          - "\$(SPC_BUILDROOT)/include/php/Zend"
          - "\$(SPC_BUILDROOT)/include/php/TSRM"
          - "\$(SPC_BUILDROOT)/include"
        LIBRARY_SEARCH_PATHS:
          - "\$(SPC_BUILDROOT)/lib"
        OTHER_LDFLAGS:
{$ldYaml}    dependencies:
{$fwYaml}
YAML;
    }

    private function sanitizedName(): string
    {
        $n = preg_replace('/[^A-Za-z0-9]/', '', $this->config->name) ?: 'Game';
        return $n . 'IOS';
    }

    private function yamlStr(string $s): string
    {
        return '"' . str_replace('"', '\"', $s) . '"';
    }

    /** @return array{0:string,1:string} [sdk, arch] */
    private function sdkAndArch(string $slice): array
    {
        return match ($slice) {
            'ios-simulator-arm64'  => ['iphonesimulator', 'arm64'],
            'ios-simulator-x86_64' => ['iphonesimulator', 'x86_64'],
            default                => ['iphoneos', 'arm64'],
        };
    }

    private function signingArgs(): string
    {
        $ios = $this->config->platforms['ios'] ?? [];
        $team = (string) ($ios['team'] ?? '');
        if ($team !== '') {
            // Real signing via the configured team; let Xcode fetch profiles.
            return "-allowProvisioningUpdates DEVELOPMENT_TEAM={$team}";
        }
        // Simulator / unsigned: ad-hoc.
        return 'CODE_SIGN_IDENTITY="-" CODE_SIGNING_REQUIRED=YES CODE_SIGNING_ALLOWED=YES';
    }

    private function findBuiltApp(string $derivedData, string $sdk): ?string
    {
        $cfgDir = $sdk === 'iphonesimulator' ? 'Debug-iphonesimulator' : 'Debug-iphoneos';
        $glob = glob($derivedData . '/Build/Products/' . $cfgDir . '/*.app');
        return ($glob && isset($glob[0])) ? $glob[0] : null;
    }

    private function requireTool(string $tool): void
    {
        $which = trim((string) shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null'));
        if ($which === '') {
            throw new \RuntimeException(
                "{$tool} not found. iOS builds require Xcode + {$tool} " .
                ($tool === 'xcodegen' ? '(brew install xcodegen).' : '(install Xcode).')
            );
        }
    }

    private function run(string $cmd): void
    {
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $tail = implode("\n", array_slice($out, -25));
            throw new \RuntimeException("Command failed (exit {$code}):\n{$cmd}\n\n{$tail}");
        }
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $target = $dst . '/' . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (is_file($dir)) {
                unlink($dir);
            }
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
