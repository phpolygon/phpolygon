<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\FileTree;
use PHPolygon\Build\HttpClient;
use PHPolygon\Build\HttpResponse;
use PHPolygon\Build\NativeUpscalerResolver;
use PHPolygon\Build\UpscalerConfig;
use PHPUnit\Framework\TestCase;

/**
 * Where the native upscaler runtimes of a build come from: FSR 3 from AMD's
 * FidelityFX SDK tag (checksummed, cached) or a local folder; DLSS only when
 * configured, from a local folder or a PRIVATE GitHub release (token, cached).
 * Anything missing is a warning - the game then builds without that upscaler.
 */
final class NativeUpscalerResolverTest extends TestCase
{
    private const GUID = '3f2a9c1e-7b4d-4e8a-9f60-1c2d3e4f5a6b';

    private string $root;
    private string $cache;
    private FakeHttp $http;
    /** @var list<array{string, string}> */
    private array $log = [];

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/phpolygon-upscaler-resolver-' . getmypid();
        $this->root = $base . '/project';
        $this->cache = $base . '/cache';
        @mkdir($this->root, 0755, true);
        $this->http = new FakeHttp();
        $this->log = [];
    }

    protected function tearDown(): void
    {
        FileTree::remove(dirname($this->root));
    }

    /** @param array<string, string>|null $ffxSha256 */
    private function resolver(?string $token = 'tok', ?array $ffxSha256 = null): NativeUpscalerResolver
    {
        $resolver = new NativeUpscalerResolver(
            $this->root,
            $this->cache,
            $this->http,
            static fn (): ?string => $token,
            $ffxSha256 ?? [
                'amd_fidelityfx_dx12.dll' => hash('sha256', 'DX12'),
                'amd_fidelityfx_vk.dll' => hash('sha256', 'VK'),
            ],
        );
        $resolver->setLogger(function (string $level, string $message): void {
            $this->log[] = [$level, $message];
        });
        return $resolver;
    }

    /** @param array<string, mixed> $json */
    private function config(array $json): UpscalerConfig
    {
        return UpscalerConfig::fromBuildJson($json, []);
    }

    private function warnings(): string
    {
        return implode("\n", array_map(
            static fn (array $e): string => $e[1],
            array_filter($this->log, static fn (array $e): bool => $e[0] === 'warning'),
        ));
    }

    private function ffxUrl(string $file): string
    {
        return 'https://raw.githubusercontent.com/GPUOpen-LibrariesAndSDKs/FidelityFX-SDK/v1.1.4/PrebuiltSignedDLL/' . $file;
    }

    /** @param list<string> $files */
    private function localDir(string $dir, array $files): string
    {
        @mkdir($this->root . '/' . $dir, 0755, true);
        foreach ($files as $file) {
            file_put_contents($this->root . '/' . $dir . '/' . $file, strtoupper($file));
        }
        return $dir;
    }

    public function testNothingConfiguredResolvesNothing(): void
    {
        $bundle = $this->resolver()->resolve($this->config([]), 'windows');
        $this->assertSame([], $bundle->files);
        $this->assertSame([], $bundle->ini);
        $this->assertSame('', $bundle->notices());
        $this->assertSame([], $this->http->requests);
    }

    public function testFsrDownloadsAmdsSignedDllsAndCachesThem(): void
    {
        $this->http->on($this->ffxUrl('amd_fidelityfx_dx12.dll'), new HttpResponse(200, 'DX12'));
        $this->http->on($this->ffxUrl('amd_fidelityfx_vk.dll'), new HttpResponse(200, 'VK'));

        $bundle = $this->resolver()->resolve($this->config(['fsr' => true]), 'windows');

        $this->assertSame(['amd_fidelityfx_dx12.dll', 'amd_fidelityfx_vk.dll'], array_map('basename', $bundle->files));
        $this->assertSame('DX12', file_get_contents($bundle->files[0]));
        $this->assertStringStartsWith($this->cache, $bundle->files[0]);
        $this->assertSame(['fsr'], $bundle->components);
        $this->assertStringContainsString('Advanced Micro Devices', $bundle->notices());

        // second build: from the cache, no request
        $this->http->requests = [];
        $again = $this->resolver()->resolve($this->config(['fsr' => true]), 'windows');
        $this->assertSame($bundle->files, $again->files);
        $this->assertSame([], $this->http->requests);
    }

    public function testFsrRefusesADllWithTheWrongChecksum(): void
    {
        $this->http->on($this->ffxUrl('amd_fidelityfx_dx12.dll'), new HttpResponse(200, 'tampered'));
        $this->http->on($this->ffxUrl('amd_fidelityfx_vk.dll'), new HttpResponse(200, 'VK'));

        $bundle = $this->resolver()->resolve($this->config(['fsr' => true]), 'windows');

        $this->assertSame([], $bundle->files);
        $this->assertSame([], $bundle->components);
        $this->assertStringContainsString('checksum', $this->warnings());
        $this->assertFileDoesNotExist($this->cache . '/upscalers/fidelityfx-v1.1.4/amd_fidelityfx_dx12.dll');
    }

    public function testFsrDownloadFailureIsAWarning(): void
    {
        $bundle = $this->resolver()->resolve($this->config(['fsr' => true]), 'windows');
        $this->assertSame([], $bundle->files);
        $this->assertStringContainsString('FSR 3', $this->warnings());
    }

    public function testFsrFromALocalFolder(): void
    {
        $dir = $this->localDir('redist/ffx', ['amd_fidelityfx_dx12.dll', 'amd_fidelityfx_vk.dll']);
        $bundle = $this->resolver()->resolve($this->config(['fsr' => ['path' => $dir]]), 'windows');
        $this->assertSame([
            $this->root . '/redist/ffx/amd_fidelityfx_dx12.dll',
            $this->root . '/redist/ffx/amd_fidelityfx_vk.dll',
        ], $bundle->files);
        $this->assertSame([], $this->http->requests);
    }

    public function testFsrFromALocalFolderWithOneBackendOnly(): void
    {
        $dir = $this->localDir('ffx', ['amd_fidelityfx_dx12.dll']);
        $bundle = $this->resolver()->resolve($this->config(['fsr' => $dir]), 'windows');
        $this->assertSame(['amd_fidelityfx_dx12.dll'], array_map('basename', $bundle->files));
        $this->assertSame(['fsr'], $bundle->components);
    }

    public function testFsrFromAnEmptyLocalFolderIsAWarning(): void
    {
        $dir = $this->localDir('ffx', []);
        $bundle = $this->resolver()->resolve($this->config(['fsr' => $dir]), 'windows');
        $this->assertSame([], $bundle->files);
        $this->assertStringContainsString('amd_fidelityfx_dx12.dll', $this->warnings());
    }

    public function testFsrHasNoLinuxRuntimeFromAmd(): void
    {
        $bundle = $this->resolver()->resolve($this->config(['fsr' => true]), 'linux');
        $this->assertSame([], $bundle->files);
        $this->assertSame([], $this->http->requests);
        $this->assertStringContainsString('linux', $this->warnings());
    }

    public function testFsrOnLinuxFromALocalFolderWithTheSharedObject(): void
    {
        $dir = $this->localDir('ffx', ['libamd_fidelityfx_vk.so']);
        $bundle = $this->resolver()->resolve($this->config(['fsr' => $dir]), 'linux');
        $this->assertSame(['libamd_fidelityfx_vk.so'], array_map('basename', $bundle->files));
    }

    public function testNothingOnMacos(): void
    {
        $dir = $this->localDir('dlss', ['vio_dlss.dll', 'nvngx_dlss.dll']);
        $bundle = $this->resolver()->resolve($this->config(['fsr' => true, 'dlss' => ['plugin' => $dir]]), 'macos');
        $this->assertSame([], $bundle->files);
        $this->assertSame([], $this->http->requests);
        $this->assertStringContainsString('macos', $this->warnings());
    }

    public function testDlssFromALocalFolderWithProjectId(): void
    {
        $dir = $this->localDir('dlss', ['vio_dlss.dll', 'nvngx_dlss.dll']);
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => $dir, 'projectId' => self::GUID]]), 'windows');
        $this->assertSame(['vio_dlss.dll', 'nvngx_dlss.dll'], array_map('basename', $bundle->files));
        $this->assertSame(['vio.dlss_project_id' => self::GUID], $bundle->ini);
        $this->assertSame(['dlss'], $bundle->components);
        $this->assertStringContainsString('NVIDIA', $bundle->notices());
        $this->assertSame('', $this->warnings());
    }

    public function testDlssAbsolutePluginPath(): void
    {
        $this->localDir('abs', ['vio_dlss.dll', 'nvngx_dlss.dll']);
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => $this->root . '/abs', 'projectId' => self::GUID]]), 'windows');
        $this->assertCount(2, $bundle->files);
    }

    public function testDlssWithoutProjectIdShipsWithAWarning(): void
    {
        $dir = $this->localDir('dlss', ['vio_dlss.dll', 'nvngx_dlss.dll']);
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => $dir]]), 'windows');
        $this->assertCount(2, $bundle->files);
        $this->assertSame([], $bundle->ini);
        $this->assertStringContainsString('projectId', $this->warnings());
    }

    public function testDlssNeedsBothFiles(): void
    {
        $dir = $this->localDir('dlss', ['vio_dlss.dll']);
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => $dir, 'projectId' => self::GUID]]), 'windows');
        $this->assertSame([], $bundle->files);
        $this->assertSame([], $bundle->ini);
        $this->assertSame([], $bundle->components);
        $this->assertStringContainsString('nvngx_dlss.dll', $this->warnings());
    }

    public function testDlssOnLinuxWithoutSharedObjectsIsNotSupported(): void
    {
        $dir = $this->localDir('dlss', ['vio_dlss.dll', 'nvngx_dlss.dll']);
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => $dir, 'projectId' => self::GUID]]), 'linux');
        $this->assertSame([], $bundle->files);
        $this->assertStringContainsString('not supported', $this->warnings());
    }

    public function testDlssOnLinuxWithSharedObjects(): void
    {
        $dir = $this->localDir('dlss', ['libvio_dlss.so', 'libnvidia-ngx-dlss.so.310.9.1']);
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => $dir, 'projectId' => self::GUID]]), 'linux');
        $this->assertSame(['libvio_dlss.so', 'libnvidia-ngx-dlss.so.310.9.1'], array_map('basename', $bundle->files));
    }

    private function githubRelease(bool $private = true): void
    {
        $this->http->on('https://api.github.com/repos/studio/php-vio-dlss', new HttpResponse(200, (string) json_encode(['private' => $private])));
        $this->http->on('https://api.github.com/repos/studio/php-vio-dlss/releases/tags/v1.0.0', new HttpResponse(200, (string) json_encode([
            'assets' => [
                ['id' => 11, 'name' => 'vio_dlss.dll'],
                ['id' => 12, 'name' => 'nvngx_dlss.dll'],
                ['id' => 13, 'name' => 'vio_dlss-v1.0.0-windows-x64.zip'],
            ],
        ])));
        // the API answers an asset download with a redirect to signed storage
        $this->http->on('https://api.github.com/repos/studio/php-vio-dlss/releases/assets/11', new HttpResponse(302, '', ['Location' => 'https://objects.githubusercontent.com/a11?sig=x']));
        $this->http->on('https://objects.githubusercontent.com/a11?sig=x', new HttpResponse(200, 'PLUGIN'));
        $this->http->on('https://api.github.com/repos/studio/php-vio-dlss/releases/assets/12', new HttpResponse(200, 'RUNTIME'));
    }

    public function testDlssFromAPrivateGithubRelease(): void
    {
        $this->githubRelease();
        $config = $this->config(['dlss' => ['plugin' => 'github:studio/php-vio-dlss@v1.0.0', 'projectId' => self::GUID]]);

        $bundle = $this->resolver()->resolve($config, 'windows');

        $this->assertSame(['vio_dlss.dll', 'nvngx_dlss.dll'], array_map('basename', $bundle->files));
        $this->assertSame('PLUGIN', file_get_contents($bundle->files[0]));
        $this->assertSame('RUNTIME', file_get_contents($bundle->files[1]));
        $this->assertSame(['vio.dlss_project_id' => self::GUID], $bundle->ini);

        foreach ($this->http->requests as [$url, $headers]) {
            $auth = in_array('Authorization: Bearer tok', $headers, true);
            // the token goes to the API only, never to the storage host of the redirect
            $this->assertSame(str_starts_with($url, 'https://api.github.com/'), $auth, $url);
            if (str_contains($url, '/releases/assets/')) {
                $this->assertContains('Accept: application/octet-stream', $headers);
            }
        }

        // cached per tag: a second build asks nobody
        $this->http->requests = [];
        $again = $this->resolver()->resolve($config, 'windows');
        $this->assertSame($bundle->files, $again->files);
        $this->assertSame([], $this->http->requests);
    }

    public function testDlssIsNeverTakenFromAPublicRepository(): void
    {
        $this->githubRelease(private: false);
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => 'github:studio/php-vio-dlss@v1.0.0']]), 'windows');
        $this->assertSame([], $bundle->files);
        $this->assertStringContainsString('public', $this->warnings());
        foreach ($this->http->requests as [$url]) {
            $this->assertStringNotContainsString('/releases/', $url);
        }
    }

    public function testDlssFromGithubNeedsAToken(): void
    {
        $this->githubRelease();
        $bundle = $this->resolver(token: null)->resolve($this->config(['dlss' => ['plugin' => 'github:studio/php-vio-dlss@v1.0.0']]), 'windows');
        $this->assertSame([], $bundle->files);
        $this->assertSame([], $this->http->requests);
        $this->assertStringContainsString('GITHUB_TOKEN', $this->warnings());
    }

    public function testDlssReleaseWithoutTheAssetIsAWarning(): void
    {
        $this->http->on('https://api.github.com/repos/studio/php-vio-dlss', new HttpResponse(200, '{"private":true}'));
        $this->http->on('https://api.github.com/repos/studio/php-vio-dlss/releases/tags/v1.0.0', new HttpResponse(200, '{"assets":[{"id":11,"name":"vio_dlss.dll"}]}'));
        $this->http->on('https://api.github.com/repos/studio/php-vio-dlss/releases/assets/11', new HttpResponse(200, 'PLUGIN'));
        $bundle = $this->resolver()->resolve($this->config(['dlss' => ['plugin' => 'github:studio/php-vio-dlss@v1.0.0']]), 'windows');
        $this->assertSame([], $bundle->files);
        $this->assertStringContainsString('nvngx_dlss.dll', $this->warnings());
    }

    public function testBothUpscalersShareOneNoticesText(): void
    {
        $ffx = $this->localDir('ffx', ['amd_fidelityfx_dx12.dll', 'amd_fidelityfx_vk.dll']);
        $dlss = $this->localDir('dlss', ['vio_dlss.dll', 'nvngx_dlss.dll']);
        $bundle = $this->resolver()->resolve($this->config(['fsr' => $ffx, 'dlss' => ['plugin' => $dlss, 'projectId' => self::GUID]]), 'windows');
        $this->assertCount(4, $bundle->files);
        $this->assertSame(['fsr', 'dlss'], $bundle->components);
        $notices = $bundle->notices();
        $this->assertStringContainsString('FidelityFX', $notices);
        $this->assertStringContainsString('This software contains source code provided by NVIDIA Corporation.', $notices);
    }
}

/** Answers registered URLs, 404 otherwise; records every request. */
final class FakeHttp implements HttpClient
{
    /** @var array<string, HttpResponse> */
    private array $responses = [];
    /** @var list<array{string, list<string>}> */
    public array $requests = [];

    public function on(string $url, HttpResponse $response): void
    {
        $this->responses[$url] = $response;
    }

    public function get(string $url, array $headers): HttpResponse
    {
        $this->requests[] = [$url, $headers];
        return $this->responses[$url] ?? new HttpResponse(404, '');
    }
}
