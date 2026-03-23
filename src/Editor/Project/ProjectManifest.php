<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Project;

class ProjectManifest
{
    /**
     * @param array<string, string> $psr4Roots Namespace => relative path
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $engineVersion,
        public readonly string $scenesPath,
        public readonly string $assetsPath,
        public readonly array $psr4Roots,
        public readonly string $entryScene,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            '_format' => 1,
            'name' => $this->name,
            'version' => $this->version,
            'engineVersion' => $this->engineVersion,
            'scenesPath' => $this->scenesPath,
            'assetsPath' => $this->assetsPath,
            'psr4Roots' => $this->psr4Roots,
            'entryScene' => $this->entryScene,
        ];
    }
}
