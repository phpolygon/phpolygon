<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class SaveSceneCommand implements CommandInterface
{
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        $data = $doc->toArray();
        $phpCode = $context->transpiler->fromArray($data);

        $sceneName = $data['name'] ?? 'untitled';
        $className = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $sceneName)));
        $scenesDir = $context->getScenesDir();

        if (!is_dir($scenesDir)) {
            mkdir($scenesDir, 0755, true);
        }

        $path = $scenesDir . DIRECTORY_SEPARATOR . $className . '.php';
        file_put_contents($path, $phpCode);
        $doc->markClean();

        return ['saved' => $path, 'dirty' => false];
    }
}
