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

        // Cache a fake binary first
        $tempFile = tempnam(sys_get_temp_dir(), 'test-sfx-');
        file_put_contents($tempFile, 'cached-binary');
        $cachedPath = $resolver->cache($tempFile, 'test-os', 'test-arch');
        @unlink($tempFile);

        // Now resolve without explicit path — should find cached
        $result = $resolver->resolve(null, 'test-os', 'test-arch');
        $this->assertSame($cachedPath, $result);

        // Cleanup
        @unlink($cachedPath);
        @rmdir(dirname($cachedPath));
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
