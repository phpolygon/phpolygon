<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\StaticPhpResolver;
use PHPUnit\Framework\TestCase;

class StaticPhpResolverTest extends TestCase
{
    public function testDetectPlatformReturnsMacosOnDarwin(): void
    {
        $platform = StaticPhpResolver::detectPlatform();

        // Can only assert it returns a valid string
        $this->assertContains($platform, ['macos', 'linux', 'windows']);
    }

    public function testDetectArchReturnsValidArch(): void
    {
        $arch = StaticPhpResolver::detectArch();

        $this->assertContains($arch, ['arm64', 'x86_64']);
    }

    public function testResolveWithExplicitPathReturnsPath(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test-sfx-');
        file_put_contents($tempFile, 'fake-binary');

        $resolver = new StaticPhpResolver();
        $result = $resolver->resolve($tempFile, 'macos', 'arm64');

        $this->assertSame($tempFile, $result);

        @unlink($tempFile);
    }

    public function testResolveWithMissingExplicitPathThrows(): void
    {
        $resolver = new StaticPhpResolver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('micro.sfx not found at');

        $resolver->resolve('/nonexistent/path/micro.sfx', 'macos', 'arm64');
    }

    public function testCacheStoresBinary(): void
    {
        $tempDir = sys_get_temp_dir() . '/phpolygon-resolver-test-' . getmypid();
        @mkdir($tempDir, 0755, true);

        $sourceFile = $tempDir . '/source.bin';
        file_put_contents($sourceFile, 'fake-sfx-binary-content');

        $resolver = new StaticPhpResolver();
        $cachedPath = $resolver->cache($sourceFile, 'macos', 'arm64');

        $this->assertFileExists($cachedPath);
        $this->assertSame('fake-sfx-binary-content', file_get_contents($cachedPath));

        // Cleanup
        $this->removeDir(dirname(dirname($cachedPath)));
        $this->removeDir($tempDir);
    }

    public function testResolveWithCachedBinary(): void
    {
        $resolver = new StaticPhpResolver();

        // Cache a fake binary in the versioned cache path
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        $cacheDir = $home . '/.phpolygon/build-cache/test-os-test-arch-php8.5';
        @mkdir($cacheDir, 0755, true);
        $cachedPath = $cacheDir . '/micro.sfx';
        file_put_contents($cachedPath, 'cached-binary');

        // Now resolve without explicit path — should find cached
        $result = $resolver->resolve(null, 'test-os', 'test-arch', 'base', '8.5');
        $this->assertSame($cachedPath, $result);

        // Cleanup
        @unlink($cachedPath);
        @rmdir($cacheDir);
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
    public function testDxcAssetIsTheWindowsZipOfTheRelease(): void
    {
        $release = ['assets' => [
            ['name' => 'linux_dxc_2026_07_29.x86_x64.tar.gz', 'browser_download_url' => 'https://example.test/linux.tar.gz'],
            ['name' => 'pdb_2026_07_29.zip', 'browser_download_url' => 'https://example.test/pdb.zip'],
            ['name' => 'dxc_2026_07_29.zip', 'browser_download_url' => 'https://example.test/dxc.zip'],
        ]];

        $this->assertSame(
            ['name' => 'dxc_2026_07_29.zip', 'url' => 'https://example.test/dxc.zip'],
            StaticPhpResolver::dxcWindowsAsset($release),
        );
        $this->assertNull(StaticPhpResolver::dxcWindowsAsset(['assets' => []]));
    }

    public function testDxcZipEntriesFollowTheArch(): void
    {
        $this->assertSame(
            ['bin/x64/dxcompiler.dll' => 'dxcompiler.dll', 'bin/x64/dxil.dll' => 'dxil.dll'],
            StaticPhpResolver::dxcZipEntries('x86_64'),
        );
        $this->assertArrayHasKey('bin/arm64/dxil.dll', StaticPhpResolver::dxcZipEntries('aarch64'));
    }

    public function testZipEntriesAreFoundWithBackslashPaths(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension missing');
        }
        // The DXC release archives store their paths as bin\x64\dxcompiler.dll.
        $file = tempnam(sys_get_temp_dir(), 'phpolygon-zip-');
        $this->assertIsString($file);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file, \ZipArchive::OVERWRITE));
        $zip->addFromString('bin\\x64\\dxcompiler.dll', 'COMPILER');
        $zip->addFromString('bin/x64/dxil.dll', 'DXIL');
        $zip->addFromString('bin\\x64\\empty.dll', '');
        $zip->close();

        $this->assertTrue($zip->open($file));
        try {
            $this->assertSame('COMPILER', StaticPhpResolver::zipEntry($zip, 'bin/x64/dxcompiler.dll'));
            $this->assertSame('DXIL', StaticPhpResolver::zipEntry($zip, 'bin/x64/dxil.dll'));
            $this->assertNull(StaticPhpResolver::zipEntry($zip, 'bin/x64/empty.dll'));
            $this->assertNull(StaticPhpResolver::zipEntry($zip, 'bin/arm64/dxil.dll'));
        } finally {
            $zip->close();
            @unlink($file);
        }
    }
}
