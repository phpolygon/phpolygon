<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class RemoveComponentCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        $entityName = is_string($this->args['entity'] ?? null) ? $this->args['entity'] : null;
        $componentClass = is_string($this->args['component'] ?? null) ? $this->args['component'] : null;

        if ($entityName === null || $componentClass === null) {
            throw new RuntimeException("Missing 'entity' or 'component' argument");
        }

        $doc->removeComponent($entityName, $componentClass);

        return ['entity' => $entityName, 'removed' => $componentClass];
    }
}
