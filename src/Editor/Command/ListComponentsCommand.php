<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;

class ListComponentsCommand implements CommandInterface
{
    public function __construct(private readonly array $args = []) {}

    public function execute(EditorContext $context): array
    {
        $grouped = $this->args['grouped'] ?? false;

        if ($grouped) {
            $result = [];
            foreach ($context->components->getByCategory() as $cat => $schemas) {
                $result[$cat] = array_map(fn($s) => $s->toArray(), $schemas);
            }
            return ['categories' => $result];
        }

        return ['components' => $context->components->toArray()];
    }
}
