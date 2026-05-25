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
     * Build the iOS product.
     *
     * Two modes:
     *   - 'debug' (default): a Development-signed `.app` for on-device testing
     *     via devicectl / the simulator.
     *   - 'release': a Release `.xcarchive` (App Store-bound). With
     *     $exportIpa, additionally export an App Store Connect `.ipa` - this
     *     needs distribution signing (paid Apple Developer Program), so it is
     *     opt-in and may fail at the export step on a free Personal Team.
     *
     * @param string $stagingDir Staged game tree (PharBuilder::stage output)
     * @param string $libphpDir  static-php-cli buildroot (lib/libphp.a + include/php)
     * @param string $outputDir  Where to place the result
     * @param string $slice      ios-arm64 | ios-simulator-arm64 | ios-simulator-x86_64
     * @param string $mode       'debug' (.app) | 'release' (.xcarchive)
     * @param bool   $exportIpa  In release mode, also export an App Store .ipa
     * @return string Path to the built product (.app, or .ipa/.xcarchive)
     */
    public function build(
        string $stagingDir,
        string $libphpDir,
        string $outputDir,
        string $slice = 'ios-arm64',
        string $mode = 'debug',
        bool $exportIpa = false
    ): string {
        $this->requireTool('xcodegen');
        $this->requireTool('xcodebuild');

        if ($mode === 'release' && $slice !== 'ios-arm64') {
            throw new \RuntimeException(
                "Release/App Store builds target ios-arm64 only (got {$slice}); " .
                'simulator slices cannot be submitted to the App Store.'
            );
        }

        $libphp = $libphpDir . '/lib/libphp.a';
        if (!file_exists($libphp)) {
            throw new \RuntimeException(
                "libphp.a not found at {$libphp}.\n" .
                "Build it first: SPC_TARGET={$slice} bin/spc build <exts> --build-embed"
            );
        }

        $work = $this->prepareProject($stagingDir, $libphpDir, $slice);
        $projectName = $this->sanitizedName();

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return $mode === 'release'
            ? $this->archive($work, $projectName, $outputDir, $exportIpa)
            : $this->buildApp($work, $projectName, $slice, $outputDir);
    }

    /**
     * Lay out the xcodegen project in a fresh temp dir: template ObjC sources,
     * the staged game tree (+ folded-in external resources + entry shim) and a
     * generated project.yml, then run xcodegen. Returns the work dir.
     */
    private function prepareProject(string $stagingDir, string $libphpDir, string $slice): string
    {
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
        $this->run('cd ' . escapeshellarg($work) . ' && xcodegen generate');

        return $work;
    }

    /** Debug build: produce a Development-signed .app and copy it out. */
    private function buildApp(string $work, string $projectName, string $slice, string $outputDir): string
    {
        [$sdk, $arch] = $this->sdkAndArch($slice);
        $signArgs = $this->signingArgs();
        $this->log("xcodebuild ({$sdk}, {$arch})");
        $this->run(
            'cd ' . escapeshellarg($work) . ' && ' .
            "xcodebuild -project {$projectName}.xcodeproj -scheme {$projectName} " .
            "-sdk {$sdk} -configuration Debug ARCHS={$arch} ONLY_ACTIVE_ARCH=YES " .
            'ENABLE_DEBUG_DYLIB=NO ' . $signArgs . ' -derivedDataPath ' . escapeshellarg($work . '/DerivedData') . ' build'
        );

        $built = $this->findBuiltApp($work . '/DerivedData', $sdk);
        if ($built === null) {
            throw new \RuntimeException('xcodebuild succeeded but no .app was found under DerivedData');
        }
        $dest = $outputDir . '/' . basename($built);
        $this->removeDir($dest);
        $this->copyDir($built, $dest);
        $this->log("app: {$dest}");

        return $dest;
    }

    /**
     * Release build: archive (-configuration Release) into a .xcarchive, then
     * optionally export an App Store Connect .ipa. The archive itself signs
     * with the team's development identity; distribution signing happens at
     * export time (or interactively in Xcode Organizer).
     */
    private function archive(string $work, string $projectName, string $outputDir, bool $exportIpa): string
    {
        $signArgs = $this->signingArgs();
        $archivePath = $work . '/' . $projectName . '.xcarchive';
        $this->log('xcodebuild archive (Release, iphoneos arm64)');
        $this->run(
            'cd ' . escapeshellarg($work) . ' && ' .
            "xcodebuild -project {$projectName}.xcodeproj -scheme {$projectName} " .
            '-sdk iphoneos -configuration Release ARCHS=arm64 ONLY_ACTIVE_ARCH=NO ' .
            'ENABLE_DEBUG_DYLIB=NO ' . $signArgs . ' -derivedDataPath ' . escapeshellarg($work . '/DerivedData') . ' ' .
            '-archivePath ' . escapeshellarg($archivePath) . ' archive'
        );
        if (!is_dir($archivePath)) {
            throw new \RuntimeException('xcodebuild archive succeeded but no .xcarchive was produced');
        }

        $archiveDest = $outputDir . '/' . $projectName . '.xcarchive';
        $this->removeDir($archiveDest);
        $this->copyDir($archivePath, $archiveDest);
        $this->log("archive: {$archiveDest}");

        if (!$exportIpa) {
            return $archiveDest;
        }

        // Export an App Store Connect .ipa. Requires distribution signing
        // (Apple Distribution cert + App Store profile, i.e. the paid program).
        $team = $this->iosOption('team');
        if ($team === '') {
            throw new \RuntimeException(
                'iOS .ipa export needs platforms.ios.team in build.json (and a paid ' .
                'Apple Developer Program membership with an App Store provisioning profile).'
            );
        }
        $exportPlist = $work . '/ExportOptions.plist';
        file_put_contents($exportPlist, $this->exportOptionsPlist($team));
        $exportDir = $work . '/export';
        $this->log('xcodebuild -exportArchive (app-store-connect)');
        $this->run(
            'cd ' . escapeshellarg($work) . ' && ' .
            'xcodebuild -exportArchive -allowProvisioningUpdates ' .
            '-archivePath ' . escapeshellarg($archiveDest) . ' ' .
            '-exportOptionsPlist ' . escapeshellarg($exportPlist) . ' ' .
            '-exportPath ' . escapeshellarg($exportDir)
        );
        $ipas = glob($exportDir . '/*.ipa') ?: [];
        if (!isset($ipas[0])) {
            throw new \RuntimeException('exportArchive succeeded but no .ipa was produced');
        }
        $ipaDest = $outputDir . '/' . basename($ipas[0]);
        @unlink($ipaDest);
        copy($ipas[0], $ipaDest);
        $this->log("ipa: {$ipaDest}");

        return $ipaDest;
    }

    /** App Store Connect export options. */
    private function exportOptionsPlist(string $team): string
    {
        return <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>method</key>
    <string>app-store-connect</string>
    <key>teamID</key>
    <string>{$team}</string>
    <key>signingStyle</key>
    <string>automatic</string>
    <key>destination</key>
    <string>export</string>
    <key>uploadSymbols</key>
    <true/>
</dict>
</plist>
PLIST;
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
        $bundleId = $this->iosOption('bundleId', $this->config->identifier);
        $team = $this->iosOption('team');
        $deploy = $this->iosOption('deploymentTarget', '14.0');
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

    /** Read a string option from build.json platforms.ios, with a default. */
    private function iosOption(string $key, string $default = ''): string
    {
        $ios = $this->config->platforms['ios'] ?? [];
        $val = $ios[$key] ?? null;
        return is_string($val) ? $val : $default;
    }

    private function signingArgs(): string
    {
        $team = $this->iosOption('team');
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
        return $glob ? $glob[0] : null;
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
