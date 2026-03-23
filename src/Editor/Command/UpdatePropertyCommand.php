<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class UpdatePropertyCommand implements CommandInterface
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
        $property = $this->args['property'] ?? null;
        $value = $this->args['value'] ?? null;

        if ($entityName === null || $componentClass === null || $property === null) {
            throw new RuntimeException("Missing 'entity', 'component', or 'property' argument");
        }

        $doc->updateProperty($entityName, $componentClass, $property, $value);

        return ['entity' => $entityName, 'component' => $componentClass, 'property' => $property];
    }
}
