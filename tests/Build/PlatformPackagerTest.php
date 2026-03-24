<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\PlatformPackager;
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
        $this->assertTrue(is_executable($result . '/TestGame'));
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
