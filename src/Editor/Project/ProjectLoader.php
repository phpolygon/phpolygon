<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

use RuntimeException;

class ProjectLoader
{
    public const MANIFEST_FILE = 'phpolygon.project.json';

    public function load(string $projectDir): ProjectManifest
    {
        $path = rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;

        if (!file_exists($path)) {
            throw new RuntimeException("Project manifest not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read project manifest: {$path}");
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid project manifest JSON: {$path}");
        }

        return $this->fromArray($data);
    }

    public function save(ProjectManifest $manifest, string $projectDir): void
    {
        $path = rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): ProjectManifest
    {
        $this->validate($data);

        return new ProjectManifest(
            name: $data['name'],
            version: $data['version'] ?? '0.1.0',
            engineVersion: $data['engineVersion'] ?? '*',
            scenesPath: $data['scenesPath'] ?? 'src/Scene',
            assetsPath: $data['assetsPath'] ?? 'assets',
            psr4Roots: $data['psr4Roots'] ?? [],
            entryScene: $data['entryScene'] ?? '',
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data): void
    {
        if (!isset($data['name']) || !is_string($data['name'])) {
            throw new RuntimeException("Project manifest must have a 'name' string field");
        }
    }
}
