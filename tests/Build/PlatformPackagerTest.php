<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\HttpClient;
use PHPolygon\Build\HttpResponse;
use PHPolygon\Build\MicroIni;
use PHPolygon\Build\NativeUpscalerResolver;
use PHPolygon\Build\PharBuilder;
use PHPolygon\Build\PlatformPackager;
use PHPolygon\Runtime\BundleExtractor;
use PHPUnit\Framework\TestCase;

class PlatformPackagerTest extends TestCase
{
    private string $tempDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpolygon-packager-test-' . getmypid();
        $this->outputDir = $this->tempDir . '/output';
        @mkdir($this->tempDir, 0755, true);
        @mkdir($this->outputDir, 0755, true);

        // Create a fake project with composer.json
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'name' => 'test/mygame',
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testPackageMacOSCreatesAppBundle(): void
    {
        $config = BuildConfig::load($this->tempDir);
        $config->name = 'TestGame';
        $config->identifier = 'com.test.game';
        $config->version = '1.0.0';

        $packager = new PlatformPackager($config);

        // Create fake binary
        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake-binary-content');

        $result = $packager->package($binaryPath, $this->outputDir, 'macos');

        $this->assertStringEndsWith('.app', $result);
        $this->assertDirectoryExists($result . '/Contents/MacOS');
        $this->assertDirectoryExists($result . '/Contents/Resources');
        $this->assertFileExists($result . '/Contents/MacOS/TestGame');
        $this->assertFileExists($result . '/Contents/Info.plist');

        // Verify Info.plist content
        $plist = file_get_contents($result . '/Contents/Info.plist');
        $this->assertStringContainsString('TestGame', $plist);
        $this->assertStringContainsString('com.test.game', $plist);
        $this->assertStringContainsString('1.0.0', $plist);
    }

    public function testPackageLinuxCreatesDirectory(): void
    {
        $config = BuildConfig::load($this->tempDir);
        $config->name = 'TestGame';

        $packager = new PlatformPackager($config);

        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake-binary-content');

        $result = $packager->package($binaryPath, $this->outputDir, 'linux');

        $this->assertDirectoryExists($result);
        $this->assertFileExists($result . '/TestGame');
        // Windows has no execute bit: is_executable() only accepts known
        // extensions there, and chmod() cannot mark an extensionless binary.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertTrue(is_executable($result . '/TestGame'));
        }
    }

    public function testPackageWindowsCreatesExe(): void
    {
        $config = BuildConfig::load($this->tempDir);
        $config->name = 'TestGame';

        $packager = new PlatformPackager($config);

        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake-binary-content');

        $result = $packager->package($binaryPath, $this->outputDir, 'windows');

        $this->assertDirectoryExists($result);
        $this->assertFileExists($result . '/TestGame.exe');
    }

    public function testPackageUnsupportedPlatformThrows(): void
    {
        $config = BuildConfig::load($this->tempDir);
        $packager = new PlatformPackager($config);

        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported platform');

        $packager->package($binaryPath, $this->outputDir, 'bsd');
    }

    public function testPackageMacOSWithIcon(): void
    {
        // Create fake icon
        $iconPath = $this->tempDir . '/icon.icns';
        file_put_contents($iconPath, 'fake-icon-data');

        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'IconGame',
            'platforms' => [
                'macos' => ['icon' => 'icon.icns'],
            ],
        ]));

        $config = BuildConfig::load($this->tempDir);
        $packager = new PlatformPackager($config);

        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake');

        $result = $packager->package($binaryPath, $this->outputDir, 'macos');

        $this->assertFileExists($result . '/Contents/Resources/icon.icns');
    }

    public function testPackageWithExternalResources(): void
    {
        // Create external resources
        @mkdir($this->tempDir . '/resources/audio', 0755, true);
        file_put_contents($this->tempDir . '/resources/audio/music.ogg', 'fake-audio');

        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'AudioGame',
            'resources' => ['external' => ['resources/audio']],
        ]));

        $config = BuildConfig::load($this->tempDir);
        $packager = new PlatformPackager($config);

        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake');

        $result = $packager->package($binaryPath, $this->outputDir, 'linux');

        $this->assertFileExists($result . '/resources/audio/music.ogg');
    }

    public function testExternalPythonRuntimeIsTrimmedInThePackageOnly(): void
    {
        $python = $this->tempDir . '/runtimes/windows-x86_64/python';
        foreach (['Lib/os.py', 'Lib/threading.py', 'Lib/ensurepip/__init__.py', 'Lib/site-packages/pip/__init__.py', 'python.exe'] as $file) {
            @mkdir(dirname($python . '/' . $file), 0755, true);
            file_put_contents($python . '/' . $file, 'x');
        }
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'PyGame',
            'resources' => [
                'external' => ['runtimes'],
                'trimPython' => [['path' => 'runtimes/*/python']],
            ],
        ]));

        $config = BuildConfig::load($this->tempDir);
        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake');
        $result = (new PlatformPackager($config))->package($binaryPath, $this->outputDir, 'windows');

        $shipped = $result . '/runtimes/windows-x86_64/python';
        $this->assertFileExists($shipped . '/python.exe');
        $this->assertFileExists($shipped . '/Lib/threading.py');
        $this->assertDirectoryDoesNotExist($shipped . '/Lib/ensurepip');
        $this->assertDirectoryDoesNotExist($shipped . '/Lib/site-packages/pip');
        // The project's runtime keeps its tooling.
        $this->assertDirectoryExists($python . '/Lib/ensurepip');
    }

    public function testExcludeAndPythonTrimBothApplyToTheShippedRuntime(): void
    {
        foreach (['Lib/os.py', 'Lib/ensurepip/__init__.py', 'Lib/idle_notes/a.txt', 'python.exe'] as $file) {
            $this->put('runtimes/windows-x86_64/python/' . $file, 'x');
        }
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'PyGame',
            'resources' => [
                'external' => ['runtimes'],
                'exclude' => ['runtimes/*/python/Lib/idle_notes'],
                'trimPython' => [['path' => 'runtimes/*/python']],
            ],
        ]));
        file_put_contents($this->tempDir . '/test-binary', 'fake');

        $result = (new PlatformPackager(BuildConfig::load($this->tempDir)))
            ->package($this->tempDir . '/test-binary', $this->outputDir, 'windows');

        $shipped = $result . '/runtimes/windows-x86_64/python';
        $this->assertFileExists($shipped . '/Lib/os.py');
        $this->assertDirectoryDoesNotExist($shipped . '/Lib/idle_notes', 'excluded');
        $this->assertDirectoryDoesNotExist($shipped . '/Lib/ensurepip', 'trimmed');
    }

    public function testExternalResourcesMayBeFilesAndHonourExclude(): void
    {
        $this->put('assets/audio/music/theme.flac', 'flac');
        $this->put('assets/audio/voice-src/de/line1.wav', 'wav');
        $this->put('assets/audio/voice/de/line1.flac', 'voice');
        $this->put('assets/audio/voice/de/take.wav', 'raw take');
        $this->put('runtimes/readme.txt', 'runtime');
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'AudioGame',
            'resources' => [
                'external' => ['assets/audio', 'runtimes/readme.txt'],
                'exclude' => ['assets/audio/voice-src', 'assets/**/*.wav'],
            ],
        ]));
        file_put_contents($this->tempDir . '/test-binary', 'fake');

        $result = (new PlatformPackager(BuildConfig::load($this->tempDir)))
            ->package($this->tempDir . '/test-binary', $this->outputDir, 'windows');

        $this->assertFileExists($result . '/assets/audio/music/theme.flac');
        $this->assertFileExists($result . '/assets/audio/voice/de/line1.flac');
        $this->assertFileExists($result . '/runtimes/readme.txt');
        $this->assertDirectoryDoesNotExist($result . '/assets/audio/voice-src');
        $this->assertFileDoesNotExist($result . '/assets/audio/voice/de/take.wav');
    }

    /**
     * macOS layout without a real build: both architectures' binaries carry only
     * a launcher, the one PHAR sits in Contents/Resources, and its assets are
     * already extracted there with the build's marker - the game never has to
     * write into the signed bundle.
     */
    public function testMacBundleCarriesOneSharedPharWithPreExtractedAssets(): void
    {
        $this->put('assets/meshes/crate.mesh.json', '{"v":1}');
        $this->put('assets/audio/theme.flac', 'flac');
        $this->put('assets/audio/voice-src/line1.wav', 'wav');
        $this->put('resources/shaders/basic.glsl', 'void main() {}');
        $this->put('src/Game.php', '<?php class Game {}');
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'TestGame',
            'identifier' => 'com.test.game',
            'resources' => ['external' => ['assets/audio'], 'exclude' => ['assets/audio/voice-src']],
        ]));
        $config = BuildConfig::load($this->tempDir);
        $phar = $this->buildPhar();
        file_put_contents($this->tempDir . '/test-binary', 'runtime+launcher');

        $packager = new PlatformPackager($config);
        $app = $packager->package($this->tempDir . '/test-binary', $this->outputDir, 'macos', 'base', [], '', $phar);

        $resources = $app . '/Contents/Resources';
        $this->assertSame('testgame.phar', $packager->macPharFile());
        $this->assertSame(['TestGame'], $this->names($app . '/Contents/MacOS'), 'no archive next to the binaries');
        $this->assertFileEquals($phar, $resources . '/testgame.phar');
        $this->assertSame('{"v":1}', file_get_contents($resources . '/assets/meshes/crate.mesh.json'));
        $this->assertSame('void main() {}', file_get_contents($resources . '/resources/shaders/basic.glsl'));
        $this->assertSame('flac', file_get_contents($resources . '/assets/audio/theme.flac'));
        $this->assertDirectoryDoesNotExist($resources . '/assets/audio/voice-src');

        $buildId = PharBuilder::readBuildId($phar);
        $this->assertNotNull($buildId);
        $this->assertTrue(BundleExtractor::isCurrent($resources . '/assets', $buildId));
        $this->assertTrue(BundleExtractor::isCurrent($resources . '/resources', $buildId));
    }

    /** Builds the fixture project's PHAR in a child process (phar.readonly=0). */
    private function buildPhar(): string
    {
        $staging = $this->tempDir . '/staging';
        $pharPath = $this->tempDir . '/game-' . uniqid() . '.phar';
        $code = sprintf(
            'require %s; $b = new PHPolygon\Build\PharBuilder(PHPolygon\Build\BuildConfig::load(%s)); $b->stage(%s); $b->build(%s, %s);',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->tempDir, true),
            var_export($staging, true),
            var_export($staging, true),
            var_export($pharPath, true),
        );
        exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));

        return $pharPath;
    }

    /** @return list<string> */
    private function names(string $dir): array
    {
        return array_values(array_diff((array) scandir($dir), ['.', '..']));
    }

    private function put(string $relative, string $content): void
    {
        $path = $this->tempDir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }

    public function testThirdPartyNoticesLandNextToTheExe(): void
    {
        $config = BuildConfig::load($this->tempDir);
        $config->name = 'TestGame';
        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake');

        $result = (new PlatformPackager($config))->package($binaryPath, $this->outputDir, 'windows', 'base', [], "Third-party software notices\r\n");

        $this->assertSame("Third-party software notices\r\n", file_get_contents($result . '/THIRD-PARTY-NOTICES.txt'));
    }

    public function testNoNoticesNoFile(): void
    {
        $config = BuildConfig::load($this->tempDir);
        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake');

        $result = (new PlatformPackager($config))->package($binaryPath, $this->outputDir, 'windows');

        $this->assertFileDoesNotExist($result . '/THIRD-PARTY-NOTICES.txt');
    }

    /**
     * build.json `upscalers` with local folders, resolved and packaged the way
     * GameBuilder does it: the runtimes sit next to the exe (php-vio looks there
     * first), the notices beside them, the project id goes into the embedded ini.
     */
    public function testWindowsBuildShipsLocalUpscalerRuntimesNextToTheExe(): void
    {
        $guid = '3f2a9c1e-7b4d-4e8a-9f60-1c2d3e4f5a6b';
        foreach (['redist/ffx/amd_fidelityfx_dx12.dll', 'redist/ffx/amd_fidelityfx_vk.dll', 'redist/dlss/vio_dlss.dll', 'redist/dlss/nvngx_dlss.dll'] as $file) {
            @mkdir(dirname($this->tempDir . '/' . $file), 0755, true);
            file_put_contents($this->tempDir . '/' . $file, basename($file));
        }
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'UpscaledGame',
            'upscalers' => ['fsr' => 'redist/ffx', 'dlss' => ['plugin' => 'redist/dlss', 'projectId' => $guid]],
        ]));
        $config = BuildConfig::load($this->tempDir);
        $resolver = new NativeUpscalerResolver($this->tempDir, $this->tempDir . '/cache', new class implements HttpClient {
            public function get(string $url, array $headers): HttpResponse
            {
                throw new \LogicException("no network for local folders: {$url}");
            }
        });
        $bundle = $resolver->resolve($config->upscalers, 'windows');
        $binaryPath = $this->tempDir . '/test-binary';
        file_put_contents($binaryPath, 'fake');

        $result = (new PlatformPackager($config))->package($binaryPath, $this->outputDir, 'windows', 'base', $bundle->files, $bundle->notices("\r\n"));

        foreach (['UpscaledGame.exe', 'amd_fidelityfx_dx12.dll', 'amd_fidelityfx_vk.dll', 'vio_dlss.dll', 'nvngx_dlss.dll', 'THIRD-PARTY-NOTICES.txt'] as $file) {
            $this->assertFileExists($result . '/' . $file);
        }
        $notices = (string) file_get_contents($result . '/THIRD-PARTY-NOTICES.txt');
        $this->assertStringContainsString('Advanced Micro Devices', $notices);
        $this->assertStringContainsString('NVIDIA Corporation', $notices);
        $this->assertSame(['vio.dlss_project_id' => $guid], $bundle->ini);
        $this->assertStringContainsString("vio.dlss_project_id={$guid}", MicroIni::block([...$config->phpIni, ...$bundle->ini]));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
