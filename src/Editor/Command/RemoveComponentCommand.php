<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class RemoveComponentCommand implements CommandInterface
{
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        $entityName = $this->args['entity'] ?? null;
        $componentClass = $this->args['component'] ?? null;

        if ($entityName === null || $componentClass === null) {
            throw new RuntimeException("Missing 'entity' or 'component' argument");
        }

        $doc->removeComponent($entityName, $componentClass);

        return ['entity' => $entityName, 'removed' => $componentClass];
    }
}
