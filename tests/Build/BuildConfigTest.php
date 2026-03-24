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

    public function testBuildJsonPharExclude(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'phar' => ['exclude' => ['**/tests', '**/vendor-extra']],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(['**/tests', '**/vendor-extra'], $config->pharExclude);
    }

    public function testBuildJsonExternalResources(): void
    {
        file_put_contents($this->tempDir . '/build.json', json_encode([
            'resources' => ['external' => ['resources/audio', 'resources/video']],
        ]));

        $config = BuildConfig::load($this->tempDir);

        $this->assertSame(['resources/audio', 'resources/video'], $config->externalResources);
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
