<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class DeleteEntityCommand implements CommandInterface
{
    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $doc = $context->activeDocument;
        if ($doc === null) {
            throw new RuntimeException("No active scene document");
        }

        if (!isset($this->args['name'])) {
            throw new RuntimeException("Missing 'name' argument");
        }
        $name = is_string($this->args['name']) ? $this->args['name'] : '';

        $doc->removeEntity($name);

        return ['deleted' => $name];
    }
}
