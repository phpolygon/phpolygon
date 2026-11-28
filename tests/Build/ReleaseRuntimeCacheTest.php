<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\FileTree;
use PHPolygon\Build\HttpClient;
use PHPolygon\Build\HttpResponse;
use PHPolygon\Build\ReleaseAssetCache;
use PHPolygon\Build\StaticPhpResolver;
use PHPUnit\Framework\TestCase;

/**
 * A cached runtime is used only while the release still publishes the asset it was
 * made from (identity record next to it), never because it exists or is newer on
 * disk. An unreadable release index fails the build unless offline mode is set.
 */
final class ReleaseRuntimeCacheTest extends TestCase
{
    private const RELEASE = 'https://api.github.com/repos/studio/runtimes/releases/tags/runtime-php8.5';
    private const DXC_RELEASE = 'https://api.github.com/repos/microsoft/DirectXShaderCompiler/releases/latest';
    private const SFX_KEY = 'macos-arm64-php8.5';

    private string $cache;
    private ReleaseHttp $http;
    /** @var list<string> */
    private array $log = [];
    private string|false $offline = false;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension missing');
        }
        $this->cache = sys_get_temp_dir() . '/phpolygon-runtime-cache-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->cache, 0755, true);
        $this->http = new ReleaseHttp();
        $this->log = [];
        $this->offline = getenv(ReleaseAssetCache::OFFLINE_ENV);
        putenv(ReleaseAssetCache::OFFLINE_ENV);
    }

    protected function tearDown(): void
    {
        putenv($this->offline === false ? ReleaseAssetCache::OFFLINE_ENV : ReleaseAssetCache::OFFLINE_ENV . '=' . $this->offline);
        if (isset($this->cache)) {
            FileTree::remove($this->cache);
        }
    }

    /** A new resolver per build, like a new `phpolygon build` process. */
    private function resolver(): StaticPhpResolver
    {
        $resolver = new StaticPhpResolver(
            $this->cache,
            new ReleaseAssetCache($this->http, pause: static function (int $seconds): void {}),
            'studio/runtimes',
        );
        $resolver->setLogger(function (string $message): void {
            $this->log[] = $message;
        });
        return $resolver;
    }

    private function resolve(): string
    {
        return $this->resolver()->resolve(null, 'macos', 'arm64');
    }

    private function logText(): string
    {
        return implode("\n", $this->log);
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $file = $this->cache . '/fixture.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
            // A fixed time: equal entries have to give equal bytes (one digest).
            $zip->setMtimeName($name, 1_767_225_600);
        }
        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);
        return $bytes;
    }

    /**
     * Publish a release: asset name => [id, zip bytes].
     *
     * @param array<string, array{int, string}> $assets
     */
    private function publish(array $assets, string $url = self::RELEASE, bool $digests = true, string $updatedAt = '2026-01-01T00:00:00Z'): void
    {
        $list = [];
        foreach ($assets as $name => [$id, $bytes]) {
            $download = "https://github.com/download/{$id}/{$name}";
            $list[] = [
                'id' => $id,
                'name' => $name,
                'updated_at' => $updatedAt,
                'size' => strlen($bytes),
                'digest' => $digests ? 'sha256:' . hash('sha256', $bytes) : null,
                'browser_download_url' => $download,
            ];
            $this->http->on($download, new HttpResponse(200, $bytes));
        }
        $this->http->on($url, new HttpResponse(200, (string) json_encode(['assets' => $list])));
    }

    private function publishSfx(string $content, int $id, bool $digests = true, string $updatedAt = '2026-01-01T00:00:00Z'): void
    {
        $this->publish(
            ['micro-sfx-base-8.5-macos-aarch64.zip' => [$id, $this->zip(['micro.sfx' => $content])]],
            digests: $digests,
            updatedAt: $updatedAt,
        );
    }

    private function downloads(): int
    {
        return count(array_filter($this->http->requests, static fn (array $r): bool => str_contains($r[0], '/download/')));
    }

    public function testACachedFileWithoutARecordIsDownloadedAgainHoweverNewItIs(): void
    {
        mkdir($this->cache . '/' . self::SFX_KEY);
        $file = $this->cache . '/' . self::SFX_KEY . '/micro.sfx';
        file_put_contents($file, 'STALE');
        touch($file, time() + 3600);
        $this->publishSfx('NEW', 1);

        $path = $this->resolve();

        $this->assertSame($file, $path);
        $this->assertSame('NEW', file_get_contents($path));
        $this->assertStringContainsString('no identity record', $this->logText());
        $this->assertSame(1, ReleaseAssetCache::record($path)['id'] ?? null);
    }

    public function testTheSameAssetIsVerifiedCurrentAndNotDownloaded(): void
    {
        $this->publishSfx('ONE', 1);
        $path = $this->resolve();
        $this->assertStringContainsString('downloaded and cached', $this->logText());
        $this->log = [];

        $this->assertSame($path, $this->resolve());

        $this->assertSame(1, $this->downloads());
        $this->assertStringContainsString('verified current', $this->logText());
    }

    public function testSigningTheCachedFileDoesNotChangeItsIdentity(): void
    {
        $this->publishSfx('ONE', 1);
        $path = $this->resolve();
        file_put_contents($path, 'ONE+SIGNATURE');
        $record = ReleaseAssetCache::record($path);

        $this->resolve();

        $this->assertSame(1, $this->downloads());
        $this->assertSame($record, ReleaseAssetCache::record($path));
    }

    public function testARepublishedReleaseReplacesTheCachedFileWhateverItsMtime(): void
    {
        $this->publishSfx('OLD', 1);
        $path = $this->resolve();
        touch($path, time() + 3600);
        $this->publishSfx('NEW', 2);

        $this->resolve();

        $this->assertSame('NEW', file_get_contents($path));
        $this->assertStringContainsString('publishes a different file', $this->logText());
    }

    public function testARepublishedIdenticalFileStaysCurrentByItsDigest(): void
    {
        $this->publishSfx('SAME', 1);
        $this->resolve();
        $this->publishSfx('SAME', 2, updatedAt: '2026-02-02T00:00:00Z');

        $this->resolve();

        $this->assertSame(1, $this->downloads());
    }

    public function testWithoutADigestIdAndUpdateTimeDecide(): void
    {
        $this->publishSfx('OLD', 1, digests: false);
        $path = $this->resolve();
        $this->resolve();
        $this->assertSame(1, $this->downloads());

        $this->publishSfx('NEW', 1, digests: false, updatedAt: '2026-02-02T00:00:00Z');
        $this->resolve();

        $this->assertSame('NEW', file_get_contents($path));
    }

    public function testAnUnreadableIndexFailsInsteadOfUsingTheCache(): void
    {
        $this->publishSfx('ONE', 1);
        $this->resolve();
        $this->http->on(self::RELEASE, new HttpResponse(404, ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ReleaseAssetCache::OFFLINE_ENV);
        $this->resolve();
    }

    public function testOfflineModeUsesTheRecordedCacheAndSaysSoLoudly(): void
    {
        $this->publishSfx('ONE', 7);
        $path = $this->resolve();
        $this->http->on(self::RELEASE, new HttpResponse(0, '', [], 'getaddrinfo failed'));
        putenv(ReleaseAssetCache::OFFLINE_ENV . '=1');
        $this->log = [];

        $this->assertSame($path, $this->resolve());

        $this->assertStringContainsString('WARNING', $this->logText());
        $this->assertStringContainsString('UNVERIFIED', $this->logText());
        $this->assertStringContainsString('asset 7', $this->logText());
        $this->assertStringContainsString('getaddrinfo failed', $this->logText());
    }

    public function testOfflineModeDoesNotAcceptAFileWithoutARecord(): void
    {
        mkdir($this->cache . '/' . self::SFX_KEY);
        file_put_contents($this->cache . '/' . self::SFX_KEY . '/micro.sfx', 'UNKNOWN');
        putenv(ReleaseAssetCache::OFFLINE_ENV . '=1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No micro.sfx binary found');
        $this->resolve();
    }

    public function testOfflineModeStillRefreshesWhenTheIndexAnswers(): void
    {
        $this->publishSfx('OLD', 1);
        $path = $this->resolve();
        $this->publishSfx('NEW', 2);
        putenv(ReleaseAssetCache::OFFLINE_ENV . '=1');

        $this->resolve();

        $this->assertSame('NEW', file_get_contents($path));
    }

    public function testADownloadThatDoesNotMatchTheDigestFailsAndLeavesTheCacheAlone(): void
    {
        $this->publishSfx('OLD', 1);
        $path = $this->resolve();
        $this->publishSfx('NEW', 2);
        // The release changed between reading the index and the download.
        $other = $this->zip(['micro.sfx' => 'NEX']);
        $this->http->on('https://github.com/download/2/micro-sfx-base-8.5-macos-aarch64.zip', new HttpResponse(200, $other));

        try {
            $this->resolve();
            $this->fail('a mismatching download must fail');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/digest|bytes/', $e->getMessage());
        }

        $this->assertSame('OLD', file_get_contents($path));
        $this->assertSame(1, ReleaseAssetCache::record($path)['id'] ?? null);
    }

    public function testAFailedDownloadOfANewerAssetFails(): void
    {
        $this->publishSfx('OLD', 1);
        $this->resolve();
        $this->publishSfx('NEW', 2);
        $this->http->on('https://github.com/download/2/micro-sfx-base-8.5-macos-aarch64.zip', new HttpResponse(404, ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be downloaded');
        $this->resolve();
    }

    public function testAnAssetTheReleaseNoLongerPublishesIsNotTakenFromTheCache(): void
    {
        $this->publishSfx('ONE', 1);
        $this->resolve();
        $this->publish([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No micro.sfx binary found');
        $this->resolve();
    }

    public function testTransientFailuresAreRetried(): void
    {
        $this->publishSfx('ONE', 1);
        $this->http->failFirst(self::RELEASE, 2, new HttpResponse(502, ''));

        $this->assertSame('ONE', file_get_contents($this->resolve()));
    }

    public function testTheIndexIsReadOncePerBuild(): void
    {
        $this->publishSfx('ONE', 1);
        $resolver = $this->resolver();
        $resolver->resolve(null, 'macos', 'arm64');
        $resolver->resolveRuntimeLibs('macos', 'arm64');

        $this->assertCount(1, array_filter($this->http->requests, static fn (array $r): bool => $r[0] === self::RELEASE));
    }

    private function publishWindows(string $steam, string $dxc, int $generation): void
    {
        $this->publish([
            'vulkan-1-dll-base-8.5-windows-x86_64.zip' => [10 * $generation + 1, $this->zip(['vulkan-1.dll' => "VK{$generation}"])],
            'd3dcompiler-47-dll-base-8.5-windows-x86_64.zip' => [10 * $generation + 2, $this->zip(['d3dcompiler_47.dll' => 'D3D'])],
            'libsteam-api-8.5-windows-x86_64.zip' => [10 * $generation + 3, $this->zip(['buildroot/bin/steam_api64.dll' => $steam])],
        ]);
        $this->publish([
            'dxc_2026_07_29.zip' => [10 * $generation + 4, $this->zip(['bin\\x64\\dxcompiler.dll' => $dxc, 'bin/x64/dxil.dll' => 'SIGN'])],
        ], self::DXC_RELEASE);
    }

    public function testEveryRuntimeLibraryFollowsTheSameRule(): void
    {
        $this->publishWindows('STEAM1', 'DXC1', 1);
        $libs = $this->resolver()->resolveRuntimeLibs('windows', 'x86_64');
        $this->assertSame(
            ['vulkan-1.dll', 'd3dcompiler_47.dll', 'dxcompiler.dll', 'dxil.dll', 'steam_api64.dll'],
            array_map('basename', $libs),
        );

        $this->publishWindows('STEAM2', 'DXC2', 2);
        $this->http->requests = [];
        $this->resolver()->resolveRuntimeLibs('windows', 'x86_64');

        $this->assertSame(['VK2', 'D3D', 'DXC2', 'SIGN', 'STEAM2'], array_map(static fn (string $f): string => (string) file_get_contents($f), $libs));
        // d3dcompiler is byte for byte the same asset: verified, not downloaded.
        $this->assertSame(3, $this->downloads());
    }

    public function testACachedDxcIsNotUsedWhenItsReleaseCannotBeRead(): void
    {
        $this->publishWindows('STEAM', 'DXC', 1);
        $this->resolver()->resolveRuntimeLibs('windows', 'x86_64');
        $this->http->on(self::DXC_RELEASE, new HttpResponse(403, ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dxc');
        $this->resolver()->resolveRuntimeLibs('windows', 'x86_64');
    }

    public function testAPublishedLibraryThatCannotBeDownloadedFails(): void
    {
        $this->publishWindows('STEAM', 'DXC', 1);
        $this->http->on('https://github.com/download/13/libsteam-api-8.5-windows-x86_64.zip', new HttpResponse(404, ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('libsteam-api');
        $this->resolver()->resolveRuntimeLibs('windows', 'x86_64');
    }

    public function testARuntimeForAnotherHostStatesThatItsVioVersionIsUnknown(): void
    {
        $probe = new \PHPolygon\Build\RuntimeProbe('linux', 'x86_64');
        $this->assertStringContainsString('php-vio version unknown', $probe->describe('/nonexistent', [], 'macos', 'arm64'));
    }

    public function testAnOutdatedBuildImageIsRefusedForMacosOnly(): void
    {
        $this->assertStringContainsString('docker build', (string) StaticPhpResolver::outdatedHarness('macos', null, true, false));
        $this->assertNull(StaticPhpResolver::outdatedHarness('macos', null, true, true));
        $this->assertNull(StaticPhpResolver::outdatedHarness('macos', '/signed/micro.sfx', true, false));
        $this->assertNull(StaticPhpResolver::outdatedHarness('macos', null, false, false));
        $this->assertNull(StaticPhpResolver::outdatedHarness('windows', null, true, false));
    }
}

/** Answers registered URLs, 404 otherwise; records every request. */
final class ReleaseHttp implements HttpClient
{
    /** @var array<string, HttpResponse> */
    private array $responses = [];
    /** @var array<string, list<HttpResponse>> */
    private array $first = [];
    /** @var list<array{string, list<string>}> */
    public array $requests = [];

    public function on(string $url, HttpResponse $response): void
    {
        $this->responses[$url] = $response;
    }

    public function failFirst(string $url, int $times, HttpResponse $response): void
    {
        $this->first[$url] = array_fill(0, $times, $response);
    }

    public function get(string $url, array $headers): HttpResponse
    {
        $this->requests[] = [$url, $headers];
        if (!empty($this->first[$url])) {
            return array_shift($this->first[$url]);
        }
        return $this->responses[$url] ?? new HttpResponse(404, '');
    }
}
