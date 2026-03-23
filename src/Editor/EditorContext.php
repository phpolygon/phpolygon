<?php

declare(strict_types=1);

namespace PHPolygon\Editor;

use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class EditorContext
{
    public ?SceneDocument $activeDocument = null;

    public function __construct(
        public readonly ProjectManifest $manifest,
        public readonly ComponentRegistry $components,
        public readonly SystemRegistry $systems,
        public readonly SceneTranspiler $transpiler,
        public readonly string $projectDir,
    ) {}

    public function getScenesDir(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . $this->manifest->scenesPath;
    }

    public function getAssetsDir(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . $this->manifest->assetsPath;
    }
}
