<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * The native upscaler runtimes of one build target (build.json `upscalers`,
 * {@see UpscalerConfig}), to be put next to the executable - where php-vio
 * looks first: on Windows `GetModuleFileName(NULL)` is the game's own exe
 * (micro.sfx + PHAR), so a built game needs no ini to find them.
 *
 * FSR 3   AMD's signed `amd_fidelityfx_dx12.dll` / `amd_fidelityfx_vk.dll`
 *         (MIT). Default source: the FidelityFX SDK tag {@see FFX_TAG}, where
 *         AMD keeps them in git (`PrebuiltSignedDLL/`); the release asset is the
 *         whole 470 MB SDK, so the build fetches the two files by tag, checks
 *         them against {@see FFX_SHA256} and caches them. Or a local folder.
 *         Windows only, unless a local folder holds `libamd_fidelityfx_vk.so`.
 * DLSS    php-vio's plugin `vio_dlss.dll` + NVIDIA's `nvngx_dlss.dll`, from a
 *         local folder or a release of a PRIVATE GitHub repository (token from
 *         GITHUB_TOKEN / GH_TOKEN / `gh auth token`, cached per tag). NVIDIA's
 *         runtime may only travel inside an application, so a public
 *         repository is refused. Linux only with `libvio_dlss.so` +
 *         `libnvidia-ngx-dlss.so.*` present (untested), never macOS.
 *
 * Nothing here fails a build: what cannot be resolved is a warning and the
 * game builds without that upscaler (php-vio then reports it unsupported).
 */
final class NativeUpscalerResolver
{
    public const string FFX_REPO = 'GPUOpen-LibrariesAndSDKs/FidelityFX-SDK';
    public const string FFX_TAG = 'v1.1.4';

    /** SHA-256 of AMD's signed runtimes at {@see FFX_TAG}. */
    public const array FFX_SHA256 = [
        'amd_fidelityfx_dx12.dll' => '12a5081257ec95b0b53ad51b4a87fb3c03f97fe0bbb59f9496968f8d50ef93a6',
        'amd_fidelityfx_vk.dll' => 'a1624cc4238fef046f30c4d80ce3f47be63fc5f5373f49e3ee9edb9960f54c78',
    ];

    private const int MAX_REDIRECTS = 5;

    /** @var \Closure(): ?string */
    private \Closure $token;

    /** @var callable(string, string): void */
    private $logger;

    /**
     * @param (\Closure(): ?string)|null $token GitHub token for private DLSS
     *        releases; null = {@see defaultToken()}
     * @param array<string, string> $ffxSha256 checksums of the FidelityFX files
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $cacheDir,
        private readonly HttpClient $http = new StreamHttpClient(),
        ?\Closure $token = null,
        private readonly array $ffxSha256 = self::FFX_SHA256,
    ) {
        $this->token = $token ?? self::defaultToken(...);
        $this->logger = static function (string $level, string $message): void {};
    }

    /** @param callable(string, string): void $logger fn(level, message) */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    public function resolve(UpscalerConfig $config, string $platform): NativeUpscalerBundle
    {
        $files = [];
        $components = [];
        $ini = [];

        if ($config->fsr) {
            $fsr = $this->resolveFsr($config->fsrPath, $platform);
            if ($fsr !== []) {
                array_push($files, ...$fsr);
                $components[] = 'fsr';
                $this->log('info', 'FSR 3: shipping ' . implode(', ', array_map('basename', $fsr)));
            }
        }

        if ($config->dlssPlugin !== null) {
            $dlss = $this->resolveDlss($config->dlssPlugin, $platform);
            if ($dlss !== []) {
                array_push($files, ...$dlss);
                $components[] = 'dlss';
                $this->log('info', 'DLSS: shipping ' . implode(', ', array_map('basename', $dlss)));
                if ($config->dlssProjectId !== '') {
                    $ini['vio.dlss_project_id'] = $config->dlssProjectId;
                } else {
                    $this->log('warning', 'DLSS: no upscalers.dlss.projectId - the plugin\'s own NGX project id is used; a shipping game sets its own random GUID');
                }
            }
        }

        return new NativeUpscalerBundle($files, $ini, $components);
    }

    // ── FSR 3 ─────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function resolveFsr(?string $path, string $platform): array
    {
        $names = match ($platform) {
            'windows' => array_keys(self::FFX_SHA256),
            'linux' => ['libamd_fidelityfx_vk.so'],
            default => [],
        };
        if ($names === []) {
            $this->log('warning', "FSR 3: no FidelityFX runtime for {$platform} - building without FSR 3");
            return [];
        }

        if ($path !== null) {
            $dir = $this->absolute($path);
            $found = [];
            foreach ($names as $name) {
                if (is_file($dir . '/' . $name)) {
                    $found[] = $dir . '/' . $name;
                }
            }
            if ($found === []) {
                $this->log('warning', 'FSR 3: ' . implode(' / ', $names) . " not found in {$dir} - building without FSR 3");
            }
            return $found;
        }

        if ($platform !== 'windows') {
            $this->log('warning', "FSR 3: AMD publishes no {$platform} runtime - set upscalers.fsr to a folder with libamd_fidelityfx_vk.so; building without FSR 3");
            return [];
        }

        $dir = $this->cacheDir . '/upscalers/fidelityfx-' . self::FFX_TAG;
        $files = [];
        foreach ($names as $name) {
            $file = $this->fetchFfx($name, $dir);
            if ($file === null) {
                return [];
            }
            $files[] = $file;
        }
        return $files;
    }

    private function fetchFfx(string $name, string $dir): ?string
    {
        $target = $dir . '/' . $name;
        $expected = $this->ffxSha256[$name] ?? null;
        if (is_file($target) && $expected !== null && hash_file('sha256', $target) === $expected) {
            return $target;
        }

        $url = 'https://raw.githubusercontent.com/' . self::FFX_REPO . '/' . self::FFX_TAG . '/PrebuiltSignedDLL/' . $name;
        $this->log('info', "FSR 3: downloading {$name} (FidelityFX SDK " . self::FFX_TAG . ')...');
        $response = $this->fetch($url, StaticPhpResolver::requestHeaders($url, null), null);
        if (!$response->ok()) {
            $this->log('warning', "FSR 3: {$name} could not be downloaded (" . self::describe($response) . ") - building without FSR 3");
            return null;
        }
        if ($expected === null || hash('sha256', $response->body) !== $expected) {
            $this->log('warning', "FSR 3: {$name} from {$url} does not match its pinned checksum - refused, building without FSR 3");
            return null;
        }
        $this->store($target, $response->body);
        return $target;
    }

    // ── DLSS ──────────────────────────────────────────────────────────────

    /**
     * File names of the plugin and NVIDIA's runtime; on Linux the runtime is
     * versioned (libnvidia-ngx-dlss.so.310.9.1), so names match as prefixes.
     *
     * @return list<string>
     */
    private static function dlssNames(string $platform): array
    {
        return match ($platform) {
            'windows' => ['vio_dlss.dll', 'nvngx_dlss.dll'],
            'linux' => ['libvio_dlss.so', 'libnvidia-ngx-dlss.so'],
            default => [],
        };
    }

    private static function matches(string $file, string $name, string $platform): bool
    {
        return $platform === 'windows' ? strcasecmp($file, $name) === 0 : str_starts_with($file, $name);
    }

    /** @return list<string> */
    private function resolveDlss(string $plugin, string $platform): array
    {
        $names = self::dlssNames($platform);
        if ($names === []) {
            $this->log('warning', "DLSS: not supported on {$platform} - building without DLSS");
            return [];
        }

        $github = UpscalerConfig::parseGithubSource($plugin);
        if ($github === null) {
            $dir = $this->absolute($plugin);
            if (is_file($dir)) {
                $dir = dirname($dir);
            }
            return $this->dlssFrom($dir, $names, $platform, "in {$dir}");
        }

        ['owner' => $owner, 'repo' => $repo, 'tag' => $tag] = $github;
        $dir = $this->cacheDir . "/upscalers/dlss/{$owner}/{$repo}/" . self::safe($tag) . "/{$platform}";
        $cached = $this->find($dir, $names, $platform);
        if (count($cached) === count($names)) {
            return $cached;
        }
        if (!$this->downloadDlss($owner, $repo, $tag, $names, $platform, $dir)) {
            return [];
        }
        return $this->dlssFrom($dir, $names, $platform, "in the release {$owner}/{$repo}@{$tag}");
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function dlssFrom(string $dir, array $names, string $platform, string $where): array
    {
        $found = $this->find($dir, $names, $platform);
        if (count($found) === count($names)) {
            return $found;
        }
        $missing = array_values(array_filter(
            $names,
            fn (string $name): bool => $this->find($dir, [$name], $platform) === [],
        ));
        $this->log('warning', $platform === 'linux'
            ? 'DLSS: not supported on linux - ' . implode(', ', array_map(static fn (string $n): string => $n . '*', $missing)) . " missing {$where} (the Linux plugin is untested); building without DLSS"
            : 'DLSS: ' . implode(', ', $missing) . " missing {$where} - building without DLSS");
        return [];
    }

    /**
     * The files of $dir matching $names, in that order (one per name).
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function find(string $dir, array $names, string $platform): array
    {
        $entries = is_dir($dir) ? (scandir($dir) ?: []) : [];
        sort($entries);
        $found = [];
        foreach ($names as $name) {
            foreach ($entries as $entry) {
                if (self::matches($entry, $name, $platform) && is_file($dir . '/' . $entry)) {
                    $found[] = $dir . '/' . $entry;
                    break;
                }
            }
        }
        return $found;
    }

    /** @param list<string> $names */
    private function downloadDlss(string $owner, string $repo, string $tag, array $names, string $platform, string $dir): bool
    {
        $source = "github:{$owner}/{$repo}@{$tag}";
        $token = ($this->token)();
        if ($token === null || $token === '') {
            $this->log('warning', "DLSS: {$source} is a private release - set GITHUB_TOKEN / GH_TOKEN or run `gh auth login`; building without DLSS");
            return false;
        }

        $api = "https://api.github.com/repos/{$owner}/{$repo}";
        $repoInfo = $this->json($this->fetch($api, StaticPhpResolver::requestHeaders($api, $token), $token));
        if ($repoInfo === null) {
            $this->log('warning', "DLSS: cannot read the repository {$owner}/{$repo} with the token - building without DLSS");
            return false;
        }
        if (($repoInfo['private'] ?? null) !== true) {
            $this->log('warning', "DLSS: {$owner}/{$repo} is public - NVIDIA's DLSS runtime may only be passed on inside an application, never from a public release; refused, building without DLSS");
            return false;
        }

        $releaseUrl = "{$api}/releases/tags/" . rawurlencode($tag);
        $release = $this->json($this->fetch($releaseUrl, StaticPhpResolver::requestHeaders($releaseUrl, $token), $token));
        if ($release === null) {
            $this->log('warning', "DLSS: no release {$tag} in {$owner}/{$repo} - building without DLSS");
            return false;
        }

        $assets = [];
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_int($asset['id'] ?? null)) {
                $assets[$asset['name']] = $asset['id'];
            }
        }

        $picked = [];
        foreach ($names as $name) {
            foreach ($assets as $assetName => $id) {
                if (self::matches($assetName, $name, $platform)) {
                    $picked[$assetName] = $id;
                    continue 2;
                }
            }
        }
        if (count($picked) !== count($names)) {
            // dlssFrom() names what is missing
            return true;
        }

        foreach ($picked as $assetName => $id) {
            $url = "{$api}/releases/assets/{$id}";
            $headers = StaticPhpResolver::requestHeaders($url, $token);
            $headers = [...array_values(array_filter($headers, static fn (string $h): bool => !str_starts_with($h, 'Accept:'))), 'Accept: application/octet-stream'];
            $this->log('info', "DLSS: downloading {$assetName} from {$source}...");
            $response = $this->fetch($url, $headers, $token);
            if (!$response->ok() || $response->body === '') {
                $this->log('warning', "DLSS: {$assetName} could not be downloaded (" . self::describe($response) . ') - building without DLSS');
                return false;
            }
            $this->store($dir . '/' . basename($assetName), $response->body);
        }
        return true;
    }

    // ── HTTP / files ──────────────────────────────────────────────────────

    /**
     * GET with redirects followed here, so the token only ever goes to
     * api.github.com - never to the storage host a download redirects to.
     *
     * @param list<string> $headers
     */
    private function fetch(string $url, array $headers, ?string $token): HttpResponse
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $response = $this->http->get($url, $headers);
            if (!$response->isRedirect()) {
                return $response;
            }
            $url = (string) $response->header('Location');
            $headers = array_values(array_filter($headers, static fn (string $h): bool => !str_starts_with($h, 'Authorization:')));
            if ($token !== null && parse_url($url, PHP_URL_HOST) === 'api.github.com') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }
        return new HttpResponse(0, '', [], 'too many redirects');
    }

    /** @return array<mixed>|null */
    private function json(HttpResponse $response): ?array
    {
        if (!$response->ok()) {
            return null;
        }
        $data = json_decode($response->body, true);
        return is_array($data) ? $data : null;
    }

    private static function describe(HttpResponse $response): string
    {
        return $response->status === 0 ? $response->error : "HTTP {$response->status}";
    }

    /** Write via a temp file, so an interrupted build leaves no half file in the cache. */
    private function store(string $target, string $content): void
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the cache directory {$dir}");
        }
        $temp = $target . '.part';
        if (file_put_contents($temp, $content) !== strlen($content) || !rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException("Cannot write {$target}");
        }
    }

    private function absolute(string $path): string
    {
        $path = rtrim($path, '/\\');
        $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
        return $isAbsolute ? $path : $this->projectRoot . '/' . $path;
    }

    private static function safe(string $tag): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $tag);
    }

    /**
     * GITHUB_TOKEN, else GH_TOKEN, else what `gh auth token` prints (the
     * GitHub CLI's login), else null.
     */
    public static function defaultToken(): ?string
    {
        foreach (['GITHUB_TOKEN', 'GH_TOKEN'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $out = @shell_exec('gh auth token 2>' . $null);
        $token = is_string($out) ? trim($out) : '';
        return preg_match('/^\S+$/', $token) === 1 ? $token : null;
    }

    private function log(string $level, string $message): void
    {
        ($this->logger)($level, $message);
    }
}
