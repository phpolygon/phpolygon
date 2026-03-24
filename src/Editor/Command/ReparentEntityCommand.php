<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class ReparentEntityCommand implements CommandInterface
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
        $newParent = is_string($this->args['parent'] ?? null) ? $this->args['parent'] : null;

        if ($entityName === null) {
            throw new RuntimeException("Missing 'entity' argument");
        }

        $doc->reparentEntity($entityName, $newParent);

        return ['entity' => $entityName, 'newParent' => $newParent];
    }
}
