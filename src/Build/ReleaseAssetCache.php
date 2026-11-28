<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * The one rule for files a build caches from GitHub releases: a cached file is
 * used only when the release still publishes what it was made from.
 *
 * Next to every cached file lies a sidecar (`<file>.asset.json`) naming that
 * published asset (release, name, id, updated_at, size, digest). It describes the
 * remote asset, never the local file, and neither the file's existence nor its
 * mtime says anything: a release tag can be deleted and created again, and a
 * cached file can be touched (re-signed, copied) at any time. A file without a
 * sidecar is stale.
 *
 * When the release index cannot be read nothing is known, so a build fails -
 * unless {@see OFFLINE_ENV} explicitly allows the recorded cache.
 */
final class ReleaseAssetCache
{
    public const string SIDECAR_SUFFIX = '.asset.json';

    /** Opt-in for offline builds: use recorded cache files when the release index is unreachable. */
    public const string OFFLINE_ENV = 'PHPOLYGON_RUNTIME_OFFLINE';

    private const int MAX_REDIRECTS = 5;

    /** @var array<string, array<mixed>|string> URL => release JSON, or why it could not be read */
    private array $releases = [];

    /** @var \Closure(int): void */
    private \Closure $pause;

    /**
     * @param int $attempts tries per request for transient failures (no response, 429, 5xx)
     * @param (\Closure(int): void)|null $pause waits the given seconds between tries
     */
    public function __construct(
        private readonly HttpClient $http = new StreamHttpClient(),
        private readonly int $attempts = 4,
        ?\Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public static function offlineAllowed(): bool
    {
        return in_array(strtolower((string) getenv(self::OFFLINE_ENV)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * GET with redirects followed here, so the token only ever goes to
     * api.github.com - never to the storage host a download redirects to.
     *
     * @param list<string> $headers
     */
    public function get(string $url, array $headers, ?string $token = null): HttpResponse
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $response = $this->getWithRetries($url, $headers);
            if (!$response->isRedirect()) {
                return $response;
            }
            $url = (string) $response->header('Location');
            $headers = array_values(array_filter($headers, static fn (string $h): bool => !str_starts_with($h, 'Authorization:')));
            if ($token !== null && $token !== '' && parse_url($url, PHP_URL_HOST) === 'api.github.com') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }
        return new HttpResponse(0, '', [], 'too many redirects');
    }

    /**
     * The release at $url, or the reason it cannot be read. Asked once per
     * instance, so every file of one build is held against the same index.
     *
     * @return array<mixed>|string
     */
    public function release(string $url, ?string $token = null): array|string
    {
        if (!isset($this->releases[$url])) {
            $response = $this->get($url, StaticPhpResolver::requestHeaders($url, $token), $token);
            $data = $response->ok() ? json_decode($response->body, true) : null;
            $this->releases[$url] = match (true) {
                !$response->ok() => self::describeFailure($response, $token !== null && $token !== ''),
                !is_array($data) || !is_array($data['assets'] ?? null) => 'the response is not a release',
                default => $data,
            };
        }
        return $this->releases[$url];
    }

    /**
     * The bytes of $asset from $url, checked against its size and digest.
     *
     * @param list<string> $headers
     * @throws \RuntimeException when the download fails or is not the published asset
     */
    public function download(ReleaseAsset $asset, string $url, array $headers, ?string $token = null): string
    {
        $response = $this->get($url, $headers, $token);
        if (!$response->ok() || $response->body === '') {
            throw new \RuntimeException("{$asset->name} could not be downloaded (" . self::describeFailure($response, $token !== null && $token !== '') . ')');
        }
        if ($asset->size !== null && strlen($response->body) !== $asset->size) {
            throw new \RuntimeException(sprintf(
                '%s: downloaded %d bytes, the release lists %d - incomplete, or the release changed during the build',
                $asset->name,
                strlen($response->body),
                $asset->size,
            ));
        }
        if ($asset->digest !== null && str_starts_with($asset->digest, 'sha256:')
            && 'sha256:' . hash('sha256', $response->body) !== $asset->digest
        ) {
            throw new \RuntimeException("{$asset->name}: the download does not match the digest of the release ({$asset->digest}) - corrupted, or the release changed during the build");
        }
        return $response->body;
    }

    /**
     * Why $file must be fetched again, or null when it is the published $asset.
     * The digest decides when both sides know it (a re-published identical file
     * stays current); otherwise id, updated_at and size have to match.
     *
     * @param string $source the release the asset belongs to, e.g. `owner/repo@tag`
     */
    public static function staleReason(string $file, string $source, ReleaseAsset $asset): ?string
    {
        if (!is_file($file)) {
            return 'not cached';
        }
        $record = self::record($file);
        if ($record === null) {
            return 'the cached file has no identity record';
        }
        if (($record['source'] ?? null) !== $source || ($record['name'] ?? null) !== $asset->name) {
            return 'the cached file comes from another asset';
        }
        $digest = $record['digest'] ?? null;
        $same = is_string($digest) && $asset->digest !== null
            ? $digest === $asset->digest
            : ($record['id'] ?? null) === $asset->id
                && ($record['updatedAt'] ?? null) === $asset->updatedAt
                && ($record['size'] ?? null) === $asset->size;
        return $same ? null : 'the release publishes a different file than the cached one';
    }

    /**
     * The sidecar of a cached file, or null when there is no readable one.
     *
     * @return array<string, mixed>|null
     */
    public static function record(string $file): ?array
    {
        $path = $file . self::SIDECAR_SUFFIX;
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data) || !is_string($data['source'] ?? null) || !is_string($data['name'] ?? null) || !is_int($data['id'] ?? null)) {
            return null;
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Put a file into the cache and record the asset it came from. The old record
     * goes first and the new one comes last, so an interrupted build leaves a
     * file that counts as stale.
     *
     * @param callable(string): bool $write writes the content to the given temporary path
     */
    public static function store(string $file, string $source, ReleaseAsset $asset, callable $write): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the cache directory {$dir}");
        }
        $sidecar = $file . self::SIDECAR_SUFFIX;
        if (is_file($sidecar) && !@unlink($sidecar)) {
            throw new \RuntimeException("Cannot remove {$sidecar}");
        }
        $temp = $file . '.part-' . getmypid();
        if (!$write($temp) || !@rename($temp, $file)) {
            @unlink($temp);
            throw new \RuntimeException("Cannot write {$file}");
        }
        $json = json_encode([
            'source' => $source,
            'name' => $asset->name,
            'id' => $asset->id,
            'updatedAt' => $asset->updatedAt,
            'size' => $asset->size,
            'digest' => $asset->digest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tempRecord = $sidecar . '.part-' . getmypid();
        if (file_put_contents($tempRecord, $json) !== strlen($json) || !@rename($tempRecord, $sidecar)) {
            @unlink($tempRecord);
            throw new \RuntimeException("Cannot write {$sidecar}");
        }
    }

    /** Why a request failed, for the build log; a rejected anonymous call gets a hint to set a token. */
    public static function describeFailure(HttpResponse $response, bool $authenticated): string
    {
        if ($response->status === 0) {
            return $response->error !== '' ? $response->error : 'no response';
        }
        $reason = "HTTP {$response->status}";
        if (!$authenticated && ($response->status === 403 || $response->status === 429)) {
            $reason .= '; anonymous GitHub API calls are rate-limited, set GITHUB_TOKEN';
        }
        return $reason;
    }

    /** @param list<string> $headers */
    private function getWithRetries(string $url, array $headers): HttpResponse
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->http->get($url, $headers);
            $transient = $response->status === 0 || $response->status === 429 || $response->status >= 500;
            if (!$transient || $attempt >= $this->attempts) {
                return $response;
            }
            ($this->pause)(2);
        }
    }
}
