<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPUnit\Framework\TestCase;

class BuildConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpolygon-test-' . getmypid();
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testDefaultsWithoutFiles(): void
    {
        $config = BuildConfig::load($this->tempDir);

        $this->assertSame('Game', $config->name);
        $this->assertSame('com.phpolygon.game', $config->identifier);
        $this->assertSame('1.0.0', $config->version);
        $this->assertSame('game.php', $config->entry);
        $this->assertSame($this->tempDir, $config->projectRoot);
    }

    public function testReadsNameFromComposerJson(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'name' => 'studio/mygame',
            'version' => '2.5.0',
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame('Mygame', $config->name);
        $this->assertSame('2.5.0', $config->version);
    }

    public function testBuildJsonOverridesComposer(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'name' => 'studio/mygame',
        ]));
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'name' => 'SuperGame',
            'identifier' => 'com.studio.supergame',
            'version' => '3.0.0',
            'entry' => 'main.php',
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame('SuperGame', $config->name);
        $this->assertSame('com.studio.supergame', $config->identifier);
        $this->assertSame('3.0.0', $config->version);
        $this->assertSame('main.php', $config->entry);
    }

    public function testBuildJsonPhpExtensions(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'php' => ['extensions' => ['glfw', 'mbstring', 'vulkan']],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(['glfw', 'mbstring', 'vulkan'], $config->phpExtensions);
    }

    public function testBuildJsonPhpIni(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'php' => ['ini' => [
                'opcache.enable_cli' => true,
                'opcache.jit' => 'tracing',
                'opcache.jit_buffer_size' => '128M',
                'memory_limit' => -1,
                'ignored' => ['nested'],
            ]],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame([
            'opcache.enable_cli' => '1',
            'opcache.jit' => 'tracing',
            'opcache.jit_buffer_size' => '128M',
            'memory_limit' => '-1',
        ], $config->phpIni);
        $this->assertSame($config->phpIni, $config->toArray()['php.ini']);
    }

    public function testPhpIniDefaultsToEmpty(): void
    {
        $this->assertSame([], BuildConfig::load($this->tempDir)->phpIni);
    }

    public function testBuildJsonPharExclude(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'phar' => ['exclude' => ['**/tests', '**/vendor-extra']],
        ]));

        $config = BuildConfig::load($this->tempDir);

        // build.json adds to the defaults; it never drops them.
        $this->assertSame([...BuildConfig::DEFAULT_PHAR_EXCLUDE, '**/vendor-extra'], $config->pharExclude);
    }

    public function testPharCompressionDefaultsToGzip(): void
    {
        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(BuildConfig::PHAR_COMPRESSION_GZIP, $config->pharCompression);
        $this->assertSame('gzip', $config->toArray()['phar.compression']);
    }

    public function testBuildJsonPharCompressionNone(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'phar' => ['compression' => 'none'],
        ]));

        $this->assertSame(BuildConfig::PHAR_COMPRESSION_NONE, BuildConfig::load($this->tempDir)->pharCompression);
    }

    public function testBuildJsonPharCompressionRejectsUnknownValues(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'phar' => ['compression' => 'bzip2'],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('phar.compression');
        BuildConfig::load($this->tempDir);
    }

    public function testBuildJsonExternalResources(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'resources' => ['external' => ['resources/audio', 'resources/video']],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(['resources/audio', 'resources/video'], $config->externalResources);
    }

    public function testBuildJsonResourcesExclude(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'resources' => [
                'external' => ['assets/audio'],
                'exclude' => ['assets/audio/voice-src', 'assets/**/*.wav'],
            ],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(['assets/audio/voice-src', 'assets/**/*.wav'], $config->resourcesExclude);
        $this->assertSame($config->resourcesExclude, $config->toArray()['resources.exclude']);
    }

    public function testResourcesExcludeDefaultsToEmpty(): void
    {
        $this->assertSame([], BuildConfig::load($this->tempDir)->resourcesExclude);
    }

    public function testResourcesExcludeRejectsPathsOutsideTheProject(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'resources' => ['exclude' => ['../other/assets']],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resources.exclude');
        BuildConfig::load($this->tempDir);
    }

    public function testResourcesExternalRejectsGlobs(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'resources' => ['external' => ['assets/*']],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resources.exclude');
        BuildConfig::load($this->tempDir);
    }

    public function testBuildJsonBuildTypes(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'buildTypes' => [
                'demo' => ['constants' => ['IS_DEMO' => true]],
            ],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertArrayHasKey('demo', $config->buildTypes);
        $this->assertTrue($config->buildTypes['demo']['constants']['IS_DEMO']);
    }

    public function testToArray(): void
    {
        $config = BuildConfig::load($this->tempDir);
        $array = $config->toArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('identifier', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('entry', $array);
        $this->assertArrayHasKey('php.extensions', $array);
        $this->assertArrayHasKey('phar.exclude', $array);
    }

    public function testRunCodeConfig(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'run' => '\App\Game::start();',
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame('\App\Game::start();', $config->run);
    }

    public function testMeshAssetsDefaultToJson(): void
    {
        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(BuildConfig::MESH_ASSETS_JSON, $config->meshAssets);
        $this->assertSame(6, $config->meshZlibLevel);
    }

    public function testMeshAssetsBinaryWithZlibLevel(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'assets' => ['meshes' => 'binary', 'meshZlib' => 0],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(BuildConfig::MESH_ASSETS_BINARY, $config->meshAssets);
        $this->assertSame(0, $config->meshZlibLevel);
        $this->assertSame('binary', $config->toArray()['assets.meshes']);
    }

    public function testUnknownMeshAssetFormatIsRejected(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode(['assets' => ['meshes' => 'gltf']]));

        $this->expectException(\InvalidArgumentException::class);
        BuildConfig::load($this->tempDir);
    }

    public function testTrimPythonEntriesDefaultToTheSafeGroups(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode(['resources' => ['trimPython' => [
            ['path' => 'runtimes/*/python'],
            ['path' => 'py', 'groups' => ['default', 'network'], 'remove' => ['{lib}/xml'], 'keep' => ['{lib}/idlelib/x.py']],
        ]]]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame([
            ['path' => 'runtimes/*/python', 'groups' => ['default'], 'remove' => [], 'keep' => []],
            ['path' => 'py', 'groups' => ['default', 'network'], 'remove' => ['{lib}/xml'], 'keep' => ['{lib}/idlelib/x.py']],
        ], $config->trimPython);
    }

    public function testTrimPythonRejectsUnknownGroups(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode(['resources' => ['trimPython' => [
            ['path' => 'py', 'groups' => ['everything']],
        ]]]));

        $this->expectException(\InvalidArgumentException::class);
        BuildConfig::load($this->tempDir);
    }

    public function testMeshZlibOutOfRangeIsRejected(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode(['assets' => ['meshZlib' => 12]]));

        $this->expectException(\InvalidArgumentException::class);
        BuildConfig::load($this->tempDir);
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
