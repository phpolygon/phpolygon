<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use PHPolygon\Audio\Speech\SpeechConfig;

class BuildConfig
{
    public string $name = 'Game';
    public string $identifier = 'com.phpolygon.game';
    public string $version = '1.0.0';
    public string $entry = 'game.php';

    /** PHP code to execute after bootstrap (e.g. "\\App\\Game::run();") */
    public string $run = '';

    /** @var array<string> */
    public array $phpExtensions = ['glfw', 'mbstring', 'zip', 'phar'];

    /**
     * build.json `php.ini`: settings embedded into the combined executable (see
     * {@see MicroIni}) – for startup-only settings such as OPcache and its JIT.
     *
     * @var array<string, string>
     */
    public array $phpIni = [];

    /** Enable multithreading support (requires ZTS PHP + parallel extension) */
    public bool $enableThreading = false;

    /** @var array<string, array<array{src: string, optional?: bool}>> Platform-specific native libs to bundle alongside the binary */
    public array $bundleLibs = [];

    /**
     * Patterns every build excludes from the staged vendor/ tree. build.json
     * `phar.exclude` adds to them - it never replaces them, so a game listing
     * one extra pattern does not quietly ship tests, examples or logs again.
     */
    public const DEFAULT_PHAR_EXCLUDE = [
        '**/tests', '**/Tests', '**/test',
        '**/docs', '**/doc',
        '**/editor', '**/.git', '**/.idea',
        '**/.phpunit*', '**/examples', '**/benchmarks',
        '**/*.log',
    ];

    /** @var array<string> Glob patterns (per path segment) to exclude from the staged vendor/ tree */
    public array $pharExclude = self::DEFAULT_PHAR_EXCLUDE;

    public const PHAR_COMPRESSION_GZIP = 'gzip';
    public const PHAR_COMPRESSION_NONE = 'none';

    /**
     * build.json `phar.compression`: how the PHAR stores its entries. `gzip`
     * (default) compresses every entry - the runtime then needs ext-zlib, which
     * the stub checks before it reads anything. `none` stores them plain.
     */
    public string $pharCompression = self::PHAR_COMPRESSION_GZIP;

    public const MESH_ASSETS_JSON = 'json';
    public const MESH_ASSETS_BINARY = 'binary';

    /**
     * build.json `assets.meshes`: how `*.mesh.json` assets ship. `json` (default)
     * ships them as authored; `binary` compiles every staged `*.mesh.json` into a
     * `*.mesh.bin` ({@see \PHPolygon\Geometry\MeshAssetLoader}) and drops the JSON
     * from the build - the project tree keeps its sources. The game must load
     * meshes through {@see \PHPolygon\Geometry\MeshAssetLoader}.
     */
    public string $meshAssets = self::MESH_ASSETS_JSON;

    /** build.json `assets.meshZlib`: zlib level 0-9 of compiled meshes (0 = none). */
    public int $meshZlibLevel = 6;

    /** @var array<string> Additional PHP files to require in stub */
    public array $additionalRequires = [];

    /** @var array<string> Resource dirs that stay external (not in PHAR) */
    public array $externalResources = [];

    /**
     * build.json `resources.trimPython`: embedded Python installs among the
     * external resources to trim in the packaged game, see {@see PythonRuntimeTrimmer}.
     * `path` is a glob relative to the package root (e.g. `runtimes/linux-x86_64/python`,
     * wildcards allowed),
     * `groups` defaults to `["default"]`.
     *
     * @var list<array{path: string, groups: list<string>, remove: list<string>, keep: list<string>}>
     */
    public array $trimPython = [];

    /** @var array<string, array<string, mixed>> Platform-specific config */
    public array $platforms = [];

    /** @var array<string, array<string, mixed>> Build type definitions with constant overrides */
    public array $buildTypes = [];

    /**
     * UI-layout transpile config (build.json `ui.transpile`): when set, the build
     * regenerates `<dir>/*.ui.json` into `<output>/<Studly>Layout.php` (namespace
     * `namespace`) on the staged tree, so the PHAR always ships zero-parse PHP.
     *
     * @var array{dir: string, output: string, namespace: string}|null
     */
    public ?array $uiTranspile = null;

    /**
     * build.json `hooks.beforeBuild`: commands run before a build stages the
     * game, see {@see BuildHookRunner}.
     *
     * @var list<array{run: list<string>, requires: list<string>, runtimeVariant: ?string}>
     */
    public array $hooksBeforeBuild = [];

    /**
     * build.json `upscalers`: native upscaler runtimes (FSR 3, DLSS) the build
     * ships next to the executable, see {@see UpscalerConfig}.
     */
    public UpscalerConfig $upscalers;

    /**
     * build.json `speech`: spoken lines generated from the translation files,
     * see {@see SpeechConfig}; null = the game has no generated speech.
     */
    public ?SpeechConfig $speech = null;

    public string $projectRoot;

    private function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->upscalers = new UpscalerConfig();
    }

    /**
     * Load config from build.json with fallbacks from composer.json
     */
    public static function load(string $projectRoot): self
    {
        $config = new self($projectRoot);

        // Read defaults from composer.json
        $composerFile = $projectRoot . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode((string) file_get_contents($composerFile), true);
            if (is_array($composer)) {
                if (isset($composer['name']) && is_string($composer['name'])) {
                    $parts = explode('/', $composer['name']);
                    $config->name = ucfirst(end($parts));
                }
                if (isset($composer['version']) && is_string($composer['version'])) {
                    $config->version = $composer['version'];
                }
            }
        }

        // Override with build.json if present
        $buildFile = $projectRoot . '/build.json';
        if (file_exists($buildFile)) {
            $build = json_decode((string) file_get_contents($buildFile), true);
            if (is_array($build)) {
                /** @var array<string, mixed> $build */
                $config->applyBuildJson($build);
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyBuildJson(array $data): void
    {
        if (isset($data['name']) && is_string($data['name'])) $this->name = $data['name'];
        if (isset($data['identifier']) && is_string($data['identifier'])) $this->identifier = $data['identifier'];
        if (isset($data['version']) && is_string($data['version'])) $this->version = $data['version'];
        if (isset($data['entry']) && is_string($data['entry'])) $this->entry = $data['entry'];
        if (isset($data['run']) && is_string($data['run'])) $this->run = $data['run'];

        $php = isset($data['php']) && is_array($data['php']) ? $data['php'] : [];
        if (isset($php['extensions']) && is_array($php['extensions'])) {
            /** @var array<string> $extensions */
            $extensions = $php['extensions'];
            $this->phpExtensions = $extensions;
        }
        if (isset($php['threading']) && $php['threading'] === true) {
            $this->enableThreading = true;
        }
        if (isset($php['ini']) && is_array($php['ini'])) {
            foreach ($php['ini'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                if (is_bool($value)) {
                    $this->phpIni[$key] = $value ? '1' : '0';
                } elseif (is_string($value) || is_int($value) || is_float($value)) {
                    $this->phpIni[$key] = (string) $value;
                }
            }
        }
        if (isset($php['bundleLibs']) && is_array($php['bundleLibs'])) {
            /** @var array<string, array<array{src: string, optional?: bool}>> $bundleLibs */
            $bundleLibs = $php['bundleLibs'];
            $this->bundleLibs = $bundleLibs;
        }

        $phar = isset($data['phar']) && is_array($data['phar']) ? $data['phar'] : [];
        if (isset($phar['exclude']) && is_array($phar['exclude'])) {
            /** @var array<string> $exclude */
            $exclude = $phar['exclude'];
            $this->pharExclude = array_values(array_unique([...self::DEFAULT_PHAR_EXCLUDE, ...$exclude]));
        }
        if (isset($phar['additionalRequires']) && is_array($phar['additionalRequires'])) {
            /** @var array<string> $additionalRequires */
            $additionalRequires = $phar['additionalRequires'];
            $this->additionalRequires = $additionalRequires;
        }
        if (isset($phar['compression'])) {
            $compression = $phar['compression'];
            if (!in_array($compression, [self::PHAR_COMPRESSION_GZIP, self::PHAR_COMPRESSION_NONE], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'build.json phar.compression must be "%s" or "%s", got %s',
                    self::PHAR_COMPRESSION_GZIP,
                    self::PHAR_COMPRESSION_NONE,
                    json_encode($compression),
                ));
            }
            $this->pharCompression = $compression;
        }

        $ui = isset($data['ui']) && is_array($data['ui']) ? $data['ui'] : [];
        if (isset($ui['transpile']) && is_array($ui['transpile'])) {
            $transpile = $ui['transpile'];
            $namespace = isset($transpile['namespace']) && is_string($transpile['namespace']) ? $transpile['namespace'] : '';
            $output = isset($transpile['output']) && is_string($transpile['output']) ? $transpile['output'] : '';
            if ($namespace !== '' && $output !== '') {
                $this->uiTranspile = [
                    'dir' => isset($transpile['dir']) && is_string($transpile['dir']) ? $transpile['dir'] : 'resources/ui',
                    'output' => $output,
                    'namespace' => $namespace,
                ];
            }
        }

        $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [];
        if (isset($assets['meshes'])) {
            if (!in_array($assets['meshes'], [self::MESH_ASSETS_JSON, self::MESH_ASSETS_BINARY], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'build.json assets.meshes must be "%s" or "%s", got %s',
                    self::MESH_ASSETS_JSON,
                    self::MESH_ASSETS_BINARY,
                    json_encode($assets['meshes']),
                ));
            }
            $this->meshAssets = $assets['meshes'];
        }
        if (isset($assets['meshZlib'])) {
            $level = $assets['meshZlib'];
            if (!is_int($level) || $level < 0 || $level > 9) {
                throw new \InvalidArgumentException('build.json assets.meshZlib must be an integer 0-9, got ' . json_encode($level));
            }
            $this->meshZlibLevel = $level;
        }

        $resources = isset($data['resources']) && is_array($data['resources']) ? $data['resources'] : [];
        if (isset($resources['external']) && is_array($resources['external'])) {
            /** @var array<string> $external */
            $external = $resources['external'];
            $this->externalResources = $external;
        }
        if (isset($resources['trimPython']) && is_array($resources['trimPython'])) {
            foreach ($resources['trimPython'] as $entry) {
                $this->trimPython[] = self::parsePythonTrim($entry);
            }
        }
        if (isset($data['platforms']) && is_array($data['platforms'])) {
            /** @var array<string, array<string, mixed>> $platforms */
            $platforms = $data['platforms'];
            $this->platforms = $platforms;
        }
        if (isset($data['buildTypes']) && is_array($data['buildTypes'])) {
            /** @var array<string, array<string, mixed>> $buildTypes */
            $buildTypes = $data['buildTypes'];
            $this->buildTypes = $buildTypes;
        }

        $hooks = isset($data['hooks']) && is_array($data['hooks']) ? $data['hooks'] : [];
        if (isset($hooks['beforeBuild']) && is_array($hooks['beforeBuild'])) {
            foreach ($hooks['beforeBuild'] as $hook) {
                $parsed = self::parseHook($hook);
                if ($parsed !== null) {
                    $this->hooksBeforeBuild[] = $parsed;
                }
            }
        }

        $this->upscalers = UpscalerConfig::fromBuildJson($data['upscalers'] ?? null, $this->phpIni);
        $this->speech = SpeechConfig::fromBuildJson($data['speech'] ?? null);
    }

    /**
     * One `resources.trimPython` entry: `{"path": "...", "groups": [...], "remove": [...], "keep": [...]}`.
     *
     * @return array{path: string, groups: list<string>, remove: list<string>, keep: list<string>}
     * @throws \InvalidArgumentException on a malformed entry or an unknown group
     */
    private static function parsePythonTrim(mixed $entry): array
    {
        if (!is_array($entry) || !is_string($entry['path'] ?? null) || $entry['path'] === '') {
            throw new \InvalidArgumentException('build.json resources.trimPython entries need a "path": ' . json_encode($entry));
        }
        $lists = [];
        foreach (['groups', 'remove', 'keep'] as $key) {
            $values = $entry[$key] ?? ($key === 'groups' ? ['default'] : []);
            if (!is_array($values)) {
                throw new \InvalidArgumentException("build.json resources.trimPython {$key} must be a list of strings");
            }
            $list = [];
            foreach ($values as $value) {
                if (!is_string($value) || $value === '') {
                    throw new \InvalidArgumentException("build.json resources.trimPython {$key} must be a list of strings");
                }
                $list[] = $value;
            }
            $lists[$key] = $list;
        }
        PythonRuntimeTrimmer::expandGroups($lists['groups']);

        return ['path' => $entry['path'], 'groups' => $lists['groups'], 'remove' => $lists['remove'], 'keep' => $lists['keep']];
    }

    /**
     * One hook entry: `{"run": [...], "requires": [...], "runtimeVariant": "..."}`
     * or just the command array. Null for anything else.
     *
     * @return array{run: list<string>, requires: list<string>, runtimeVariant: ?string}|null
     */
    private static function parseHook(mixed $hook): ?array
    {
        if (!is_array($hook)) {
            return null;
        }
        if (array_is_list($hook)) {
            $hook = ['run' => $hook];
        }
        $run = $hook['run'] ?? null;
        if (!is_array($run) || $run === []) {
            return null;
        }
        $command = [];
        foreach ($run as $part) {
            if (!is_string($part) || $part === '') {
                return null;
            }
            $command[] = $part;
        }
        $requires = [];
        foreach (is_array($hook['requires'] ?? null) ? $hook['requires'] : [] as $function) {
            if (is_string($function) && $function !== '') {
                $requires[] = $function;
            }
        }
        $variant = $hook['runtimeVariant'] ?? null;

        return [
            'run' => $command,
            'requires' => $requires,
            'runtimeVariant' => is_string($variant) && $variant !== '' ? $variant : null,
        ];
    }

    /**
     * Get the final list of PHP extensions, including parallel if threading is enabled.
     *
     * @return array<string>
     */
    public function getResolvedExtensions(): array
    {
        $extensions = $this->phpExtensions;
        if ($this->enableThreading && !in_array('parallel', $extensions, true)) {
            $extensions[] = 'parallel';
        }
        return $extensions;
    }

    /**
     * Get the static-php-cli build variant (base or zts).
     */
    public function getPhpVariant(): string
    {
        return $this->enableThreading ? 'zts' : 'base';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'identifier' => $this->identifier,
            'version' => $this->version,
            'entry' => $this->entry,
            'php.extensions' => $this->getResolvedExtensions(),
            'php.threading' => $this->enableThreading,
            'php.variant' => $this->getPhpVariant(),
            'php.ini' => $this->phpIni,
            'php.bundleLibs' => $this->bundleLibs,
            'phar.exclude' => $this->pharExclude,
            'phar.additionalRequires' => $this->additionalRequires,
            'phar.compression' => $this->pharCompression,
            'resources.external' => $this->externalResources,
            'resources.trimPython' => $this->trimPython,
            'assets.meshes' => $this->meshAssets,
            'assets.meshZlib' => $this->meshZlibLevel,
            'platforms' => $this->platforms,
            'buildTypes' => $this->buildTypes,
            'hooks.beforeBuild' => $this->hooksBeforeBuild,
            'upscalers' => $this->upscalers->toArray(),
            'speech' => $this->speech?->toArray(),
        ];
    }
}
