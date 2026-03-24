<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class UpdatePropertyCommand implements CommandInterface
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
        $property = is_string($this->args['property'] ?? null) ? $this->args['property'] : null;
        $value = $this->args['value'] ?? null;

        if ($entityName === null || $componentClass === null || $property === null) {
            throw new RuntimeException("Missing 'entity', 'component', or 'property' argument");
        }

        $doc->updateProperty($entityName, $componentClass, $property, $value);

        return ['entity' => $entityName, 'component' => $componentClass, 'property' => $property];
    }
}
