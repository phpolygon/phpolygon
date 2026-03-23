<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Editor\Project;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\ProjectManifest;

class ProjectLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpolygon_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up
        $files = glob($this->tempDir . '/*');
        foreach ($files ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    public function testLoadManifest(): void
    {
        file_put_contents($this->tempDir . '/phpolygon.project.json', json_encode([
            '_format' => 1,
            'name' => 'Code Tycoon',
            'version' => '0.1.0',
            'engineVersion' => '^0.4',
            'scenesPath' => 'src/Scene',
            'assetsPath' => 'assets',
            'psr4Roots' => ['CodeTycoon\\' => 'src/'],
            'entryScene' => 'MainMenu',
        ]));

        $loader = new ProjectLoader();
        $manifest = $loader->load($this->tempDir);

        $this->assertSame('Code Tycoon', $manifest->name);
        $this->assertSame('0.1.0', $manifest->version);
        $this->assertSame('^0.4', $manifest->engineVersion);
        $this->assertSame('src/Scene', $manifest->scenesPath);
        $this->assertSame('MainMenu', $manifest->entryScene);
        $this->assertArrayHasKey('CodeTycoon\\', $manifest->psr4Roots);
    }

    public function testSaveAndReload(): void
    {
        $manifest = new ProjectManifest(
            name: 'Test Game',
            version: '1.0.0',
            engineVersion: '*',
            scenesPath: 'scenes',
            assetsPath: 'res',
            psr4Roots: ['TestGame\\' => 'src/'],
            entryScene: 'Intro',
        );

        $loader = new ProjectLoader();
        $loader->save($manifest, $this->tempDir);

        $loaded = $loader->load($this->tempDir);
        $this->assertSame('Test Game', $loaded->name);
        $this->assertSame('Intro', $loaded->entryScene);
    }

    public function testMissingManifestThrows(): void
    {
        $loader = new ProjectLoader();
        $this->expectException(\RuntimeException::class);
        $loader->load($this->tempDir);
    }

    public function testMissingNameThrows(): void
    {
        file_put_contents($this->tempDir . '/phpolygon.project.json', json_encode([
            'version' => '1.0',
        ]));

        $loader = new ProjectLoader();
        $this->expectException(\RuntimeException::class);
        $loader->load($this->tempDir);
    }
}
