<?php

declare(strict_types=1);

namespace PHPolygon\Build;

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

    /** Enable multithreading support (requires ZTS PHP + parallel extension) */
    public bool $enableThreading = false;

    /** @var array<string, array<array{src: string, optional?: bool}>> Platform-specific native libs to bundle alongside the binary */
    public array $bundleLibs = [];

    /** @var array<string> Glob patterns to exclude from PHAR */
    public array $pharExclude = [
        '**/tests', '**/Tests', '**/test',
        '**/docs', '**/doc',
        '**/editor', '**/.git', '**/.idea',
        '**/.phpunit*', '**/examples',
    ];

    /** @var array<string> Additional PHP files to require in stub */
    public array $additionalRequires = [];

    /** @var array<string> Resource dirs that stay external (not in PHAR) */
    public array $externalResources = [];

    /** @var array<string, array<string, mixed>> Platform-specific config */
    public array $platforms = [];

    /** @var array<string, array<string, mixed>> Build type definitions with constant overrides */
    public array $buildTypes = [];

    public string $projectRoot;

    private function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
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
        if (isset($php['bundleLibs']) && is_array($php['bundleLibs'])) {
            /** @var array<string, array<array{src: string, optional?: bool}>> $bundleLibs */
            $bundleLibs = $php['bundleLibs'];
            $this->bundleLibs = $bundleLibs;
        }

        $phar = isset($data['phar']) && is_array($data['phar']) ? $data['phar'] : [];
        if (isset($phar['exclude']) && is_array($phar['exclude'])) {
            /** @var array<string> $exclude */
            $exclude = $phar['exclude'];
            $this->pharExclude = $exclude;
        }
        if (isset($phar['additionalRequires']) && is_array($phar['additionalRequires'])) {
            /** @var array<string> $additionalRequires */
            $additionalRequires = $phar['additionalRequires'];
            $this->additionalRequires = $additionalRequires;
        }

        $resources = isset($data['resources']) && is_array($data['resources']) ? $data['resources'] : [];
        if (isset($resources['external']) && is_array($resources['external'])) {
            /** @var array<string> $external */
            $external = $resources['external'];
            $this->externalResources = $external;
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
            'php.bundleLibs' => $this->bundleLibs,
            'phar.exclude' => $this->pharExclude,
            'phar.additionalRequires' => $this->additionalRequires,
            'resources.external' => $this->externalResources,
            'platforms' => $this->platforms,
            'buildTypes' => $this->buildTypes,
        ];
    }
}
