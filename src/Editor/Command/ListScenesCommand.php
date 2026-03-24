<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;

class ListScenesCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    /** @param array<string, mixed> $args */
    public function __construct(array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $scenesDir = $context->getScenesDir();
        $scenes = [];

        if (is_dir($scenesDir)) {
            $iterator = new \DirectoryIterator($scenesDir);
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php' && !$file->isDot()) {
                    $scenes[] = $file->getBasename('.php');
                }
            }
            sort($scenes);
        }

        return ['scenes' => $scenes];
    }
}
