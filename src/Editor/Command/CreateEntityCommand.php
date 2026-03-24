<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class CreateEntityCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        $name = is_string($this->args['name'] ?? null) ? $this->args['name'] : 'NewEntity';
        $parent = is_string($this->args['parent'] ?? null) ? $this->args['parent'] : null;

        $doc->addEntity($name, $parent);

        return ['created' => $name, 'parent' => $parent];
    }
}
