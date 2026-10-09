<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use PHPolygon\Rendering\NativeUpscalerIni;

/**
 * build.json `upscalers`: the native upscaler runtimes a build ships next to
 * the executable, resolved by {@see NativeUpscalerResolver}.
 *
 * ```json
 * "upscalers": {
 *   "fsr": true,
 *   "dlss": { "plugin": "../php-vio-dlss/dist", "projectId": "<random GUID>" }
 * }
 * ```
 *
 *   fsr    true = AMD's signed FidelityFX runtimes from the FidelityFX SDK tag
 *          the engine pins (downloaded, checksummed, cached); a string or
 *          `{"path": ...}` = a local folder holding them; false/absent = none.
 *   dlss   `{"plugin": ..., "projectId": ...}` or just the plugin source:
 *          a local folder with vio_dlss.dll + nvngx_dlss.dll, or
 *          `github:<owner>/<repo>@<tag>` - a release of a PRIVATE repository
 *          (NVIDIA's runtime may only be passed on inside an application).
 *          projectId = the game's NGX project id, baked into the embedded ini
 *          as vio.dlss_project_id.
 */
final class UpscalerConfig
{
    private const GITHUB = '#^github:([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)@(\S+)$#';

    public function __construct(
        /** Ship the FidelityFX (FSR 3) runtimes. */
        public readonly bool $fsr = false,
        /** Local folder of the FidelityFX runtimes; null = AMD's release. */
        public readonly ?string $fsrPath = null,
        /** DLSS plugin source (folder or github:owner/repo@tag); null = no DLSS. */
        public readonly ?string $dlssPlugin = null,
        /** Normalised NGX project id, '' = php-vio plugin's own. */
        public readonly string $dlssProjectId = '',
    ) {}

    public function isEmpty(): bool
    {
        return !$this->fsr && $this->dlssPlugin === null;
    }

    /**
     * @param array<string, string> $phpIni build.json `php.ini` (a different
     *        vio.dlss_project_id there is a conflict)
     * @throws \InvalidArgumentException on anything malformed
     */
    public static function fromBuildJson(mixed $data, array $phpIni): self
    {
        if ($data === null) {
            return new self();
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \InvalidArgumentException('build.json upscalers must be an object like {"fsr": true, "dlss": {"plugin": "...", "projectId": "..."}}');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, ['fsr', 'dlss'], true)) {
                throw new \InvalidArgumentException("build.json upscalers.{$key} is unknown (supported: fsr, dlss)");
            }
        }

        [$fsr, $fsrPath] = self::parseFsr($data['fsr'] ?? false);
        [$plugin, $projectId] = self::parseDlss($data['dlss'] ?? false);

        $iniId = $phpIni['vio.dlss_project_id'] ?? '';
        if ($projectId !== '' && $iniId !== '' && strtolower(trim($iniId, " {}")) !== $projectId) {
            throw new \InvalidArgumentException(
                "build.json upscalers.dlss.projectId ({$projectId}) contradicts php.ini vio.dlss_project_id ({$iniId}) - set it in one place"
            );
        }

        return new self($fsr, $fsrPath, $plugin, $projectId);
    }

    /** @return array{bool, ?string} */
    private static function parseFsr(mixed $fsr): array
    {
        if (is_bool($fsr)) {
            return [$fsr, null];
        }
        if (is_array($fsr) && array_keys($fsr) === ['path']) {
            $fsr = $fsr['path'];
        }
        if (is_string($fsr) && trim($fsr) !== '') {
            return [true, $fsr];
        }
        throw new \InvalidArgumentException('build.json upscalers.fsr must be true, false, a folder or {"path": "<folder>"}');
    }

    /** @return array{?string, string} */
    private static function parseDlss(mixed $dlss): array
    {
        if ($dlss === false) {
            return [null, ''];
        }
        if (is_string($dlss)) {
            $dlss = ['plugin' => $dlss];
        }
        if (!is_array($dlss)) {
            throw new \InvalidArgumentException('build.json upscalers.dlss must be {"plugin": "<folder | github:owner/repo@tag>", "projectId": "<GUID>"}');
        }
        foreach (array_keys($dlss) as $key) {
            if (!in_array($key, ['plugin', 'projectId'], true)) {
                throw new \InvalidArgumentException("build.json upscalers.dlss.{$key} is unknown (supported: plugin, projectId)");
            }
        }
        $plugin = $dlss['plugin'] ?? null;
        if (!is_string($plugin) || trim($plugin) === '') {
            throw new \InvalidArgumentException('build.json upscalers.dlss.plugin is required: a folder with vio_dlss.dll + nvngx_dlss.dll or github:<owner>/<repo>@<tag>');
        }
        if (str_starts_with($plugin, 'github:') && self::parseGithubSource($plugin) === null) {
            throw new \InvalidArgumentException("build.json upscalers.dlss.plugin '{$plugin}' must look like github:<owner>/<repo>@<tag>");
        }

        $projectId = $dlss['projectId'] ?? '';
        if (!is_string($projectId)) {
            throw new \InvalidArgumentException('build.json upscalers.dlss.projectId must be a GUID string');
        }
        if (trim($projectId) !== '') {
            try {
                $projectId = NativeUpscalerIni::projectId($projectId);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('build.json upscalers.dlss.projectId: ' . $e->getMessage(), 0, $e);
            }
        }

        return [$plugin, $projectId];
    }

    /**
     * `github:<owner>/<repo>@<tag>` split up, or null for anything else.
     *
     * @return array{owner: string, repo: string, tag: string}|null
     */
    public static function parseGithubSource(string $source): ?array
    {
        if (preg_match(self::GITHUB, $source, $m) !== 1) {
            return null;
        }
        return ['owner' => $m[1], 'repo' => $m[2], 'tag' => $m[3]];
    }

    /** @return array{fsr: bool, fsrPath: ?string, dlssPlugin: ?string, dlssProjectId: string} */
    public function toArray(): array
    {
        return [
            'fsr' => $this->fsr,
            'fsrPath' => $this->fsrPath,
            'dlssPlugin' => $this->dlssPlugin,
            'dlssProjectId' => $this->dlssProjectId,
        ];
    }
}
