<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\FileTree;
use PHPolygon\Build\UpscalerConfig;
use PHPUnit\Framework\TestCase;

/**
 * build.json `upscalers`: which native upscaler runtimes a build ships next to
 * the executable (FSR 3 from AMD's FidelityFX SDK, DLSS through the php-vio
 * plugin), and the DLSS project id it bakes into the runtime's ini.
 */
final class UpscalerConfigTest extends TestCase
{
    private const GUID = '3f2a9c1e-7b4d-4e8a-9f60-1c2d3e4f5a6b';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpolygon-upscaler-config-' . getmypid();
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        FileTree::remove($this->tempDir);
    }

    /** @param array<string, mixed> $build */
    private function load(array $build): BuildConfig
    {
        file_put_contents($this->tempDir . '/build.json', json_encode($build));
        return BuildConfig::load($this->tempDir);
    }

    public function testNothingConfiguredShipsNothing(): void
    {
        $config = $this->load(['name' => 'X']);
        $this->assertFalse($config->upscalers->fsr);
        $this->assertNull($config->upscalers->fsrPath);
        $this->assertNull($config->upscalers->dlssPlugin);
        $this->assertSame('', $config->upscalers->dlssProjectId);
        $this->assertTrue($config->upscalers->isEmpty());
    }

    public function testFsrTrueUsesAmdsRelease(): void
    {
        $upscalers = $this->load(['upscalers' => ['fsr' => true]])->upscalers;
        $this->assertTrue($upscalers->fsr);
        $this->assertNull($upscalers->fsrPath);
        $this->assertFalse($upscalers->isEmpty());
    }

    public function testFsrFromALocalDirectory(): void
    {
        $upscalers = $this->load(['upscalers' => ['fsr' => ['path' => 'redist/ffx']]])->upscalers;
        $this->assertTrue($upscalers->fsr);
        $this->assertSame('redist/ffx', $upscalers->fsrPath);
    }

    public function testFsrStringIsALocalDirectory(): void
    {
        $this->assertSame('redist/ffx', $this->load(['upscalers' => ['fsr' => 'redist/ffx']])->upscalers->fsrPath);
    }

    public function testFsrFalseIsOff(): void
    {
        $this->assertFalse($this->load(['upscalers' => ['fsr' => false]])->upscalers->fsr);
    }

    public function testDlssWithLocalPluginAndProjectId(): void
    {
        $upscalers = $this->load(['upscalers' => ['dlss' => [
            'plugin' => '../php-vio-dlss/dist',
            'projectId' => '{' . strtoupper(self::GUID) . '}',
        ]]])->upscalers;
        $this->assertSame('../php-vio-dlss/dist', $upscalers->dlssPlugin);
        // normalised the way php-vio wants it
        $this->assertSame(self::GUID, $upscalers->dlssProjectId);
    }

    public function testDlssFromAPrivateGithubRelease(): void
    {
        $upscalers = $this->load(['upscalers' => ['dlss' => ['plugin' => 'github:studio/php-vio-dlss@v1.2.0']]])->upscalers;
        $this->assertSame('github:studio/php-vio-dlss@v1.2.0', $upscalers->dlssPlugin);
        $this->assertSame(['owner' => 'studio', 'repo' => 'php-vio-dlss', 'tag' => 'v1.2.0'], UpscalerConfig::parseGithubSource($upscalers->dlssPlugin));
    }

    public function testLocalPluginIsNoGithubSource(): void
    {
        $this->assertNull(UpscalerConfig::parseGithubSource('dist'));
    }

    public function testDlssStringIsThePlugin(): void
    {
        $this->assertSame('dist', $this->load(['upscalers' => ['dlss' => 'dist']])->upscalers->dlssPlugin);
    }

    public function testDlssFalseIsOff(): void
    {
        $this->assertNull($this->load(['upscalers' => ['dlss' => false]])->upscalers->dlssPlugin);
    }

    public function testDlssWithoutPluginIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('upscalers.dlss.plugin');
        $this->load(['upscalers' => ['dlss' => ['projectId' => self::GUID]]]);
    }

    public function testMalformedGithubSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('github:<owner>/<repo>@<tag>');
        $this->load(['upscalers' => ['dlss' => ['plugin' => 'github:studio/php-vio-dlss']]]);
    }

    public function testProjectIdMustBeAGuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('upscalers.dlss.projectId');
        $this->load(['upscalers' => ['dlss' => ['plugin' => 'dist', 'projectId' => 'my-game']]]);
    }

    public function testUnknownKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('xess');
        $this->load(['upscalers' => ['xess' => true]]);
    }

    public function testFsrOfTheWrongTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('upscalers.fsr');
        $this->load(['upscalers' => ['fsr' => 3]]);
    }

    public function testUpscalersMustBeAnObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->load(['upscalers' => true]);
    }

    public function testProjectIdConflictingWithPhpIniIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vio.dlss_project_id');
        $this->load([
            'php' => ['ini' => ['vio.dlss_project_id' => '11111111-2222-4333-8444-555555555555']],
            'upscalers' => ['dlss' => ['plugin' => 'dist', 'projectId' => self::GUID]],
        ]);
    }

    public function testToArrayShowsTheUpscalers(): void
    {
        $config = $this->load(['upscalers' => ['fsr' => true, 'dlss' => ['plugin' => 'dist', 'projectId' => self::GUID]]]);
        $this->assertSame(
            ['fsr' => true, 'fsrPath' => null, 'dlssPlugin' => 'dist', 'dlssProjectId' => self::GUID],
            $config->toArray()['upscalers'],
        );
    }
}
