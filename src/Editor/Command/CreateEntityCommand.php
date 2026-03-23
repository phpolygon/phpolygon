<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class CreateEntityCommand implements CommandInterface
{
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        $name = $this->args['name'] ?? 'NewEntity';
        $parent = $this->args['parent'] ?? null;

        $doc->addEntity($name, $parent);

        return ['created' => $name, 'parent' => $parent];
    }
}
